<?php


namespace App\Command;


use App\Entity\Service;
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

class ImportEntities extends Command
{
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
        $this->setName('app:import-entities');
        $this->setDescription('Loads activity codification from raw dataset');
        $this->addOption('delete', null,InputOption::VALUE_OPTIONAL, "Deletes entities not present in the raw database", false);
        $this->addOption('from-sheet', null,InputOption::VALUE_REQUIRED, "Imports data from a Google Sheet e.g. spreadsheetId/sheetId");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $this->loadActivities();

        return Command::SUCCESS;
    }

    protected function loadActivities(): void
    {
        $raw = $this->rawConnection;
        $em = $this->entityManager;
        $activitiesRepo = $em->getRepository(Service::class);

        // Bypass ORM to get a list of existing recipients used to track deleted activities
        $tableName = $em->getClassMetadata(Service::class)->getTableName();
        $nameColumn = $em->getClassMetadata(Service::class)->getColumnName('name');
        $deleted = array_flip(iterator_to_array($em->getConnection()->executeQuery("SELECT DISTINCT {$nameColumn} FROM {$tableName}")->iterateColumn()));

        $sql = "
SELECT DISTINCT TRIM(`name`) as `name`, hours, hours_onpremises, hours_remote
FROM activity
        ";

        foreach ($raw->executeQuery($sql)->iterateAssociative() as ['name' => $name, 'hours' => $hours, 'hours_onpremises' => $onPremises, 'hours_remote' => $remote]) {
            assert((int) $hours === ((int) $onPremises + (int) $remote), "Total hours {$hours} differs from the sum of on premises hours {$onPremises} and remote hours {$remote}");
            assert(!empty($name), "Activity name is empty");

            $activity = $activitiesRepo->findOneBy(['name' => $name]);
            if (!$activity)
                $activity = new Service();

            $activity->setName($name);
            $activity->setHours((int) $hours);
            $activity->setHoursOnPremises((int)$onPremises);

            $errors = $this->validator->validate($activity);
            assert(!count($errors), "Cannot validate activity {$activity->getName()}: ". $errors);

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

        /** @var Service[] $updated */
        $updated = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityUpdates());
        /** @var Service[] $added */
        $added = array_map(fn($c) => $c->getName(), $em->getUnitOfWork()->getScheduledEntityInsertions());
        /** @var Service[] $deleted */
        $deleted = array_map(fn($r) => $r->getName(), $em->getUnitOfWork()->getScheduledEntityDeletions());

        $this->output->writeln("Added activities: ". join(', ', array_values($added)));
        $this->output->writeln("Updated activities: ". join(', ', array_values($updated)));
        $this->output->writeln("<info>DELETED activities</info>: ". join(', ', array_values($deleted)));

        $em->flush();
    }
}
