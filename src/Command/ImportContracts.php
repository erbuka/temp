<?php


namespace App\Command;


use App\Entity\Activity;
use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use JetBrains\PhpStorm\Deprecated;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Class ImportContracts
 * @package App\Command
 *
 * Each recipient is allowed to have only 1 contract.
 * If a contract already exists, then contracted services are either added or updated.
 */
class ImportContracts extends Command
{
    protected static $defaultName = 'app:import-contracts';

    protected EntityManagerInterface $entityManager;
    protected Connection $rawConnection;
    protected ValidatorInterface $validator;
    protected OutputInterface $output;
    protected InputInterface $input;

    public function __construct(EntityManagerInterface $entityManager, Connection $rawConnection, ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->rawConnection = $rawConnection;
        $this->validator = $validator;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Loads contracts from raw dataset');
//        $this->addOption('delete', null,InputOption::VALUE_OPTIONAL, "Deletes entities not present in the raw database", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

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

            foreach ($this->rawConnection->executeQuery($sql, ['company' => $recipient->getName()])->iterateAssociative() as ['service' => $serviceName, 'hours' => $hours, 'consultant' => $consultantName]) {
                // TODO if contracted (service, consultant) already exists, update it (hours?)

                /** @var Activity $service */
                $service = $this->entityManager->getRepository(Activity::class)->find($serviceName);
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
                } else {
                    // TODO throw if recipient has already contracted (service, consultant)
                    $output->writeln("Duplicated contracted service: recipient='{$recipient->getName()}', service='{$service->getName()}', consultant='{$consultant->getName()}'");
                }

                // Move flushes outside the loop, but use DQL to get existing ContractedService's which are not yet flushed.
                $this->entityManager->flush();
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
}
