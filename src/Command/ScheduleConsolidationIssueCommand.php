<?php


namespace App\Command;

use App\ConsultantScheduleGenerator;
use App\Entity\Schedule;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:schedule-debug',
)]
class ScheduleConsolidationIssueCommand extends Command
{
    const DATE_NOTIME = 'Y-m-d';
    const CONTRACTED_SERVICES_VIEW = 'contracted_service_extd';

    protected OutputInterface $output;
    protected InputInterface $input;
    protected EntityManagerInterface $entityManager;
    protected ValidatorInterface $validator;

    public function __construct(EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $schedule = $this->entityManager->getRepository(Schedule::class)->find(1);
        $schedule->loadTasksIntoSlots();
        $output->writeln($schedule->getStats());

        if (count($errors = $this->validator->validate($schedule)) > 0)
            throw new \Exception("Cannot validate schedule ". $errors);

        $s = new Schedule($schedule->getFrom(), $schedule->getTo());

        foreach ($schedule->getTasks() as $t)
            $s->addTask(clone $t);

        $s->loadTasksIntoSlots();
        $s->consolidateNonOverlappingTasksDaily();
        $output->writeln($s->getStats());

        $this->entityManager->persist($s);
        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
