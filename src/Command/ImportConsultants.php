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

class ImportConsultants extends Command
{
    const RAW_TABLE = 'consultant';
    const SHEET_COLUMNS_MAP = [
        // DBAL named parameter => sheet column index
        'name' => 0,
        'title' => 1,
        'job_title' => 2,
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
        $this->setName('app:import-consultants');
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
        $consultantsRepository = $em->getRepository(Consultant::class);

        // Bypass ORM to get a list of existing recipients
        $tableName = $em->getClassMetadata(Consultant::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Consultant::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT `name`, title, job as job_title
FROM ".static::RAW_TABLE."
";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'title' => $title, 'job_title' => $jobTitle]) {
            $consultant = $consultantsRepository->findOneBy(['name' => $name]);
            if (!$consultant)
                $consultant = new Consultant();

            $consultant->setName(ucwords(strtolower($name)));
            $consultant->setTitle(trim($title));
            $consultant->setJobTitle(trim($jobTitle));

            $errors = $this->validator->validate($consultant);
            if (count($errors) > 0) throw new \Exception("Cannot validate Consultant {$consultant->getName()}: ". $errors);

            $em->persist($consultant);
            if (array_key_exists($consultant->getName(), $deleted))
                unset($deleted[$consultant->getName()]);
        }

        if (false !== $this->input->getOption('delete')) {
            foreach ($deleted as $name => $_) {
                $c = $consultantsRepository->findOneBy(['name' => $name]);
                if ($c) {
                    $question = new ConfirmationQuestion("<question>Delete consultant '{$c->getName()}' ?</question> [no]", false);
                    if ($this->getHelper('question')->ask($this->input, $this->output, $question)) {
                        $em->remove($c);
                    }
                }
            }
        } else {
            $this->output->writeln("<info>MISSING (removed?) Consultants:</info>: ". join(', ', array_keys($deleted)));
        }

        $em->getUnitOfWork()->computeChangeSets();

        /** @var Consultant[] $updated */
        $updated = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityUpdates());
        /** @var Consultant[] $added */
        $added = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityInsertions());
        /** @var Consultant[] $deleted */
        $deleted = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityDeletions());

        $this->output->writeln("Added consultants: ". join(', ', array_values($added)));
        $this->output->writeln("Updated consultants: ". join(', ', array_values($updated)));
        $this->output->writeln("<info>DELETED consultants</info>: ". join(', ', array_values($deleted)));

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
(name, title, job)
VALUES (:name, :title, :job_title)
");

        foreach ($sheetRows as $row) {
            $insert->bindValue($name = 'name', trim($row[$sheetColumnsMap[$name]]));
            $insert->bindValue($name = 'title', $row[$sheetColumnsMap[$name]] ?? null);
            $insert->bindValue($name = 'job_title', $row[$sheetColumnsMap[$name]] ?? null);

            $insert->executeStatement();
        }
    }
}
