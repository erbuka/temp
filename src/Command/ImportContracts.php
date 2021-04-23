<?php


namespace App\Command;


use App\Entity\Service;
use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class ImportContracts
 * @package App\Command
 *
 * Each recipient is allowed to have only 1 contract.
 * If a contract already exists, then contracted services are either added or updated.
 *
 * Each contract is identifier by its recipient.
 *
 * Data sources:
 *   - Google Sheet
 *   - DB Table
 *
 * Sync steps:
 *  1. Copy data to the raw database from the Google Sheet, row by row.
 *  2. Leverage SQL to do magic.
 *  1. detect added/removed contracts (match recipient)
 *  2. for each existing contract, update contracted services. Notice that contracted services are owned by each contract.
 *
 * Ask confirmation for contract removal
 *
 */
class ImportContracts extends Command
{
    protected static $defaultName = 'app:import-contracts';

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
        $this->setDescription('Loads contracts from raw dataset');
        $this->addOption('from-sheet', null,InputOption::VALUE_REQUIRED, "Imports data from a properly formatted Google Sheet");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $fromSheet = $input->getOption('from-sheet');
        if ($fromSheet) {
            $this->importSheetData($fromSheet);
            return Command::SUCCESS;

        }

        $sql = "
SELECT TRIM(activity_type) as service, hours, amount_eur, TRIM(consultant) as consultant
FROM company_consultant_activity
WHERE `company` = :company
        ";

        foreach ($this->getRecipients() as $recipient) {
            /** @var $recipient Recipient */
//            $this->output->writeln('Recipient:'. $recipient->getName());

            // TODO warn/skip if recipient has already a contract
            // Are multiple contracts per recipient allowed? I dont think so

            // 1. detected whether a contract already exists for (recipient, Array<(consultant, service)>)
            // throw error if recipient has already contracted (service, consultant)
            $contract = $this->entityManager->getRepository(Contract::class)->findOneBy(['recipient' => $recipient]);
            if (!$contract) {
                $contract = new Contract();
            }

            $contract->setRecipient($recipient);
            $this->entityManager->persist($contract);

            // Ideally we flush after the contract

            foreach ($this->rawConnection->executeQuery($sql, ['company' => $recipient->getName()])->iterateAssociative() as ['service' => $serviceName, 'hours' => $hours, 'consultant' => $consultantName]) {
                // TODO if contracted (service, consultant) already exists, update it (hours?)

                /** @var Service $service */
                $service = $this->entityManager->getRepository(Service::class)->find($serviceName);
                if (!$service) throw new \Exception("Cannot retrieve service '{$serviceName}'");

                /** @var Consultant $consultant */
                $consultant = $this->entityManager->getRepository(Consultant::class)->findOneBy(['name' => $consultantName]);
                if (!$consultant) throw new \Exception("Cannot retrieve consultant '{$consultantName}'");

                $contractedService = $this->entityManager->getRepository(ContractedService::class)->findOneBy(['contract' => $contract, 'service' => $service, 'consultant' => $consultant]);
                if (!$contractedService) {
                    $cs = new ContractedService();
                    $cs->setService($service);
                    $cs->setConsultant($consultant);
                    $this->entityManager->persist($cs);
                    $contract->addContractedService($cs);

                    $this->entityManager->flush();
                    $query = $this->entityManager->createQuery("SELECT cs FROM ". ContractedService::class ." cs");
                    $res = $query->getResult();
                } else {
                    // TODO throw if recipient has already contracted (service, consultant)
                    $output->writeln("Duplicated contracted service: recipient='{$recipient->getName()}', service='{$service->getName()}', consultant='{$consultant->getName()}'");
                }

                // Move flushes outside the loop, but use DQL to get existing ContractedService's which are not yet flushed.
//                $this->entityManager->flush();
            }

            // fetch raw contracted services: Array<(service, consultant, hours)>
            // throw if the recipient has already contracted (service, consultant)
            //   => should throw for AZIENDA AGRICOLA CASABIANCA S.R.L. / CECCHI MARCO

            // Create ContractedService[]
            // Create Contract($recipient, $contractedServices);
//            $output->writeln("<info>Flushing contract recipient={$recipient->getName()}");

        }

        return Command::SUCCESS;
    }

    /**
     *
     * @param Contract $contract
     * @param array<array> $data
     */
    protected function updateContract(Contract $contract, array $data)
    {

    }

    /**
     * @return \Generator<int, Recipient>
     * @throws \Doctrine\DBAL\Exception
     */
    protected function getRecipients(): \Generator
    {
        $em = $this->entityManager;
        $recipientRepository = $em->getRepository(Recipient::class);

        $sql = "
SELECT DISTINCT TRIM(company) as `name`
FROM company_consultant_activity
        ";

        foreach ($this->rawConnection->executeQuery($sql)->iterateColumn() as $name) {
            $recipient = $recipientRepository->findOneBy(['name' => $name]);

            if (!$recipient) {
                throw new \Exception("Unable to find recipient {$name}. Did you import/update recipients via app:import-entities?");
            }

            yield $recipient;
        }
    }

    protected function importSheetData(string $sheetId)
    {
        $this->authorizeSheetsClient();

        $service = new \Google_Service_Sheets($this->sheetsClient);
        $resp = $service->spreadsheets_values->get($sheetId, 'A1:D10');
        $values = $resp->getValues();

        dump($values);
    }

    protected function authorizeSheetsClient()
    {
        $questioner = $this->getHelper('question');

        $tokenPath = "{$this->cacheDir}/google_api_token.json";

        $this->sheetsClient->setApplicationName('Gestionale CONAGRIVET');
        $this->sheetsClient->setScopes(\Google_Service_Sheets::SPREADSHEETS_READONLY);


        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $this->sheetsClient->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($this->sheetsClient->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($this->sheetsClient->getRefreshToken()) {
                $this->sheetsClient->fetchAccessTokenWithRefreshToken($this->sheetsClient->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $this->sheetsClient->createAuthUrl();
                $this->output->writeln("Visit <info>{$authUrl}</info>");

                $authCode = $questioner->ask($this->input, $this->output, new Question('Enter the verification code: '));

                // Exchange authorization code for an access token.
                $accessToken = $this->sheetsClient->fetchAccessTokenWithAuthCode($authCode);
                $this->sheetsClient->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new \Exception(join(', ', $accessToken));
                }
            }

            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($this->sheetsClient->getAccessToken()));
        }
    }
}
