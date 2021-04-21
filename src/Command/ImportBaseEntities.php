<?php


namespace App\Command;


use App\Entity\Activity;
use App\Entity\Consultant;
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

class ImportBaseEntities extends Command
{
    protected static $defaultName = 'app:import-base-entities';

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
        $this->setDescription('Loads activity codification from raw dataset');
        $this->addOption('delete', null,InputOption::VALUE_OPTIONAL, "Deletes entities not present in the raw database", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $this->loadActivities();
        $this->loadConsultants();
        $this->loadRecipients();

        return Command::SUCCESS;
    }

    protected function loadActivities(): void
    {
        $raw = $this->rawConnection;
        $em = $this->entityManager;
        $activitiesRepo = $em->getRepository(Activity::class);

        // Bypass ORM to get a list of existing recipients used to track deleted activities
        $tableName = $em->getClassMetadata(Activity::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Activity::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT DISTINCT `name`, hours, hours_onpremises, hours_remote
FROM activity
        ";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'hours' => $hours, 'hours_onpremises' => $onPremises, 'hours_remote' => $remote]) {
            assert((int) $hours === ((int) $onPremises + (int) $remote), "Total hours {$hours} differs from the sum of on premises hours {$onPremises} and remote hours {$remote}");
            assert(!empty($name), "Activity name is empty");

            $activity = $activitiesRepo->findOneBy(['name' => $name]);
            if (!$activity)
                $activity = new Activity();

            $activity->setName($name);
            $activity->setHours((int) $hours);
            $activity->setHoursOnPremises((int)$onPremises);

            $errors = $this->validator->validate($activity);
            assert(!empty($errors), "Cannot validate activity {$activity->getName()}: ". $errors);

            $em->persist($activity);
            if (array_key_exists($activity->getName(), $deleted))
                unset($deleted[$activity->getName()]);
        }

        if (false !== $this->input->getOption('delete')) {
            foreach ($deleted as $name => $_) {
                $a = $activitiesRepo->findOneBy(['name' => $name]);
                if ($a) {
                    $question = new ConfirmationQuestion("<question>Delete activity '{$a->getName()}' ?</question> [no]", false);
                    if ($this->getHelper('question')->ask($this->input, $this->output, $question)) {
                        $em->remove($a);
                    }
                }
            }
        } else {
            $this->output->writeln("<info>MISSING (to be removed) activities:</info>: ". join(', ', array_keys($deleted)));
        }

        $em->getUnitOfWork()->computeChangeSets();

        /** @var Activity[] $updated */
        $updated = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityUpdates());
        /** @var Activity[] $added */
        $added = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityInsertions());
        /** @var Activity[] $deleted */
        $deleted = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityDeletions());

        $this->output->writeln("Added activities: ". join(', ', array_values($added)));
        $this->output->writeln("Updated activities: ". join(', ', array_values($updated)));
        $this->output->writeln("<info>DELETED activities</info>: ". join(', ', array_values($deleted)));

        $em->flush();
    }

    protected function loadRecipients(): void
    {
        $raw = $this->rawConnection;
        $em = $this->entityManager;
        $recipientRepository = $em->getRepository(Recipient::class);

        // Get a list of existing recipient names to detect removed ones
        $tableName = $em->getClassMetadata(Recipient::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Recipient::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT DISTINCT company as `name`, company_vat as vat, company_address as address
FROM company_consultant_activity
GROUP BY `name`
";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'vat' => $vat, 'address' => $address]) {
            $recipient = $recipientRepository->findOneBy(['name' => $name]);
            if (!$recipient)
                $recipient = new Recipient();

            $recipient->setName($name);
            $recipient->setTaxId($vat);
            $recipient->setHeadquarters($address);

            $errors = $this->validator->validate($recipient);
            assert(!empty($errors), "Cannot validate activity {$recipient->getName()}: ". $errors);

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
            $this->output->writeln("<info>MISSING (to be removed) Recipients:</info>: ". join(', ', array_keys($deleted)));
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
    }

    protected function loadConsultants(): void
    {
        $raw = $this->rawConnection;
        $em = $this->entityManager;
        $consultantsRepository = $em->getRepository(Consultant::class);

        // Bypass ORM to get a list of existing recipients
        $tableName = $em->getClassMetadata(Consultant::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Consultant::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT DISTINCT consultant as `name`, GROUP_CONCAT(DISTINCT consultant_category SEPARATOR ' ') as job_title
FROM company_consultant_activity
GROUP BY `name`
        ";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'job_title' => $jobTitle]) {
            $consultant = $consultantsRepository->findOneBy(['name' => $name]);
            if (!$consultant)
                $consultant = new Consultant();

            $consultant->setName($name);
            $consultant->setJobTitle($jobTitle);

            $errors = $this->validator->validate($consultant);
            assert(!empty($errors), "Cannot validate activity {$consultant->getName()}: ". $errors);

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
            $this->output->writeln("<info>MISSING (to be removed) Consultants:</info>: ". join(', ', array_keys($deleted)));
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
    }

    #[Deprecated]
    protected function truncateEntityTable(string $className)
    {
        $meta = $this->entityManager->getClassMetadata($className);
        $conn = $this->entityManager->getConnection();
        $platform = $conn->getDatabasePlatform();
        assert($conn->isAutoCommit(), "Auto commit not enabled by default?");

//        $this->rawConnection->beginTransaction();
//        $this->rawConnection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $q = $platform->getTruncateTableSQL($meta->getTableName(), true);
        $conn->executeStatement($q);
//        $conn->commit();
    }
}
