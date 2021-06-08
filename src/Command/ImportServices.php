<?php


namespace App\Command;


use App\Entity\Service;
use App\Entity\Consultant;
use App\Entity\Recipient;
use App\Utils;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use JetBrains\PhpStorm\Deprecated;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ImportServices extends Command
{
    const RAW_TABLE = 'services';
    const SHEET_COLUMNS_MAP = [
        // DBAL named parameter => sheet column index
        'name' => 0,
        'category' => 1,
        'ab' => 2,
        'description' => 3,
        'reasons' => 4,
        'steps' => 5,
        'expectations' => 6,
        'hours' => 7,
        'hours_onpremises' => 8,
        'hours_remote' => 9,
        'after' => 10,
        'before' => 11,
    ];

    protected EntityManagerInterface $entityManager;
    protected Connection $rawConnection;
    protected ValidatorInterface $validator;
    protected OutputInterface $output;
    protected InputInterface $input;
    protected \Google_Client $sheetsClient;
    protected string $cacheDir;

    public function __construct(EntityManagerInterface $entityManager, Connection $rawConnection, ValidatorInterface $validator, \Google_Client $sheetsClient, string $cacheDir)
    {
        $this->entityManager = $entityManager;
        $this->rawConnection = $rawConnection;
        $this->validator = $validator;
        $this->sheetsClient = $sheetsClient;
        $this->cacheDir = $cacheDir;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('app:import-services');
        $this->setDescription('Loads consultants from raw dataset');
        $this->addOption('from-sheet', null,InputOption::VALUE_REQUIRED, "Imports data from a Google Sheet e.g. spreadsheetId/sheetId");
        $this->addOption('delete', null,InputOption::VALUE_OPTIONAL, "Deletes entities not present in the raw database", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $fromSheet = $input->getOption('from-sheet');
        if ($fromSheet) {
            // Parses argument value
            [$spreadsheetId, $sheetId] = explode('/', $fromSheet);
            if (empty($spreadsheetId) || empty($sheetId)) {
                $output->writeln("<error>Missing spreadsheet or sheet id from '{$fromSheet}'</error>");
                return Command::FAILURE;
            }

            $this->importSheetData($spreadsheetId, $sheetId);
        }

        $raw = $this->rawConnection;
        $em = $this->entityManager;
        $servicesRepository = $em->getRepository(Service::class);

        // Bypass ORM to get a list of existing recipients
        $tableName = $em->getClassMetadata(Service::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Service::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT TRIM(name) as name, hours, hours_onpremises, hours_remote, description, category, reasons, expectations, steps, `after`, `before`
FROM ".static::RAW_TABLE."
";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as [
            'name' => $name,
            'hours' => $hours,
            'hours_onpremises' => $hoursOnPremises,
            'hours_remote' => $hoursRemote,
            'description' => $description,
            'category' => $category,
            'reasons' => $reasons,
            'expectations' => $expectations,
            'steps' => $steps,
            'after' => $after,
            'before' => $before,
        ]) {
            if (intval($hours) !== (intval($hoursOnPremises) + intval($hoursRemote)))
                throw new \Exception("Total hours does not equal the sum or remote and on-premises for {$name}");

            $service = $servicesRepository->findOneBy(['name' => $name]);
            if (!$service)
                $service = new Service();

            $service->setName($name);
            $service->setDescription(trim($description));
            $service->setCategory(trim($category));
            $service->setHours(intval($hours));
            $service->setHoursOnPremises(intval($hoursOnPremises));
            $service->setReasons(static::textListToArray($reasons));
            $service->setExpectations(trim($expectations));
            $service->setSteps(static::textListToArray($steps));
            if ($after && ($afterDate = \DateTime::createFromFormat('d/m/Y', $after, new \DateTimeZone('UTC')))) {
                $afterDate->setTime(0 , 0);
                $service->setFromDate($afterDate);
            }
            if ($before && ($beforeDate = \DateTime::createFromFormat('d/m/Y', $before, new \DateTimeZone('UTC')))) {
                $beforeDate->setTime(23, 59);
                $service->setToDate($beforeDate);
            }

            $errors = $this->validator->validate($service);
            if (count($errors) > 0) throw new \Exception("Cannot validate Service {$service->getName()}: ". $errors);

            $em->persist($service);
            if (array_key_exists($service->getName(), $deleted))
                unset($deleted[$service->getName()]);
        }

        if (false !== $this->input->getOption('delete')) {
            foreach ($deleted as $name => $_) {
                $c = $servicesRepository->findOneBy(['name' => $name]);
                if ($c) {
                    $question = new ConfirmationQuestion("<question>Delete service '{$c->getName()}' ?</question> [no]", false);
                    if ($this->getHelper('question')->ask($this->input, $this->output, $question)) {
                        $em->remove($c);
                    }
                }
            }
        } else {
            $this->output->writeln("<info>MISSING (removed?) Services:</info>: ". join(', ', array_keys($deleted)));
        }

        $em->getUnitOfWork()->computeChangeSets();

        /** @var Service[] $updated */
        $updated = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityUpdates());
        /** @var Service[] $added */
        $added = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityInsertions());
        /** @var Service[] $deleted */
        $deleted = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityDeletions());

        $this->output->writeln("Added services: ". join(', ', array_values($added)));
        $this->output->writeln("Updated services: ". join(', ', array_values($updated)));
        $this->output->writeln("<info>DELETED services</info>: ". join(', ', array_values($deleted)));

        $em->flush();

        return Command::SUCCESS;
    }

    protected function importSheetData(string $spreadsheetId, string $sheetId)
    {
        Utils::authorizeSheetsClient($this->sheetsClient, $this->cacheDir, $this, $this->input, $this->output);

        $service = new \Google_Service_Sheets($this->sheetsClient);
        $sheetColumnsMap = static::SHEET_COLUMNS_MAP;
        $sheetTitle = Utils::getSheetNameFromId($this->sheetsClient, $spreadsheetId, $sheetId);
        if (empty($sheetTitle))
            throw new \Exception("Sheet '{$sheetId}' does not exist in spreadsheet '{$spreadsheetId}'");

        $sheetRows = $service->spreadsheets_values->get($spreadsheetId, "'{$sheetTitle}'")->getValues();
        array_shift($sheetRows); // Drops first row (header)

        Utils::truncateTable($this->rawConnection, static::RAW_TABLE);

        $insert = $this->rawConnection->prepare("
INSERT INTO ".static::RAW_TABLE."
(`name`, category, ab, hours, hours_onpremises, hours_remote, description, reasons, expectations, steps, `after`, `before`)
VALUES (:name, :category, :ab, :hours, :hours_onpremises, :hours_remote, :description, :reasons, :expectations, :steps, :after, :before)
");

        foreach ($sheetRows as $row) {
            $insert->bindValue($name = 'name', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'category', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'ab', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'hours', $row[$sheetColumnsMap[$name]], ParameterType::INTEGER);
            $insert->bindValue($name = 'hours_onpremises', $row[$sheetColumnsMap[$name]], ParameterType::INTEGER);
            $insert->bindValue($name = 'hours_remote', $row[$sheetColumnsMap[$name]], ParameterType::INTEGER);
            $insert->bindValue($name = 'description', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'reasons', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'expectations', $row[$sheetColumnsMap[$name]]);
            $insert->bindValue($name = 'steps', $row[$sheetColumnsMap[$name]]);

            $insert->bindValue($name = 'after', !empty($row[$sheetColumnsMap[$name]]) ? $row[$sheetColumnsMap[$name]] : null);
            $insert->bindValue($name = 'before', !empty($row[$sheetColumnsMap[$name]]) ? $row[$sheetColumnsMap[$name]] : null);

            $insert->executeStatement();
        }
    }

    private static function textListToArray(string $list): array
    {
        $items = [];
        foreach (explode('**', $list) as &$item) {
            $item = trim($item, ",;. \n\r\t\v\0");
            if (!empty($item))
                $items[] = $item;
        }

        return $items;
    }
}
