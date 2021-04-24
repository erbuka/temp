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

class ImportRecipients extends Command
{
    const RAW_TABLE = 'recipient';
    const SHEET_COLUMNS_MAP = [
        // DBAL named parameter => sheet column index
        'name' => 0,
        'taxid' => 1,
        'headquarters' => 2,
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
        $this->setName('app:import-recipients');
        $this->setDescription('Loads recipients from raw dataset');
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
        $recipientRepository = $em->getRepository(Recipient::class);

        // Get a list of existing recipient names to detect removed ones
        $tableName = $em->getClassMetadata(Recipient::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Recipient::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT name, taxid, headquarters
FROM ".static::RAW_TABLE."
";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'taxid' => $taxId, 'headquarters' => $address]) {
            $recipient = $recipientRepository->findOneByTaxId($taxId);
            if (!$recipient)
                $recipient = new Recipient();

            if (strlen($taxId) == 16) {
                $recipient->setFiscalCode($taxId);
            } else
                $recipient->setVatId($taxId);

            $recipient->setName($name);
            $recipient->setHeadquarters($address);

            $errors = $this->validator->validate($recipient);
            if (count($errors) > 0) throw new \Exception("Cannot validate Recipient {$recipient->getName()}: ". $errors);

            $em->persist($recipient);
            if (array_key_exists($recipient->getName(), $deleted))
                unset($deleted[$recipient->getName()]);
        }

        if (false !== $this->input->getOption('delete')) {
            foreach ($deleted as $name => $_) {
                $r = $recipientRepository->findOneBy(['name' => $name]);
                if ($r) {
                    $question = new ConfirmationQuestion("<question>Delete recipient '{$r->getName()}' ?</question> [no]", false);
                    if ($this->getHelper('question')->ask($this->input, $this->output, $question)) {
                        $em->remove($r);
                    }
                }
            }
        } else {
            $this->output->writeln("<info>MISSING (removed?) Recipients:</info>: ". join(', ', array_keys($deleted)));
        }

        $em->getUnitOfWork()->computeChangeSets();

        /** @var Recipient[] $updated */
        $updated = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityUpdates());
        /** @var Recipient[] $added */
        $added = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityInsertions());
        /** @var Recipient[] $deleted */
        $deleted = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityDeletions());

        $this->output->writeln("ADDED Recipients: ". join(', ', array_values($added)));
        $this->output->writeln("UPDATED Recipients: ". join(', ', array_values($updated)));
        $this->output->writeln("<info>DELETED Recipients</info>: ". join(', ', array_values($deleted)));

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
(taxid, name, headquarters)
VALUES (:taxid, :name, :headquarters)
");

        foreach ($sheetRows as $row) {
            $insert->bindValue($name = 'taxid', trim($row[$sheetColumnsMap[$name]]));
            $insert->bindValue($name = 'name', trim($row[$sheetColumnsMap[$name]]));
            $insert->bindValue($name = 'headquarters', trim($row[$sheetColumnsMap[$name]]));

            assert($insert->executeStatement() > 0, "Affected rows < 0");
        }
    }
}
