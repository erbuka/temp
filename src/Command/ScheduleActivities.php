<?php


namespace App\Command;


use App\ConsultantScheduleGenerator;
use App\Entity\Consultant;
use App\Entity\Service;
use App\Entity\Schedule;
use App\Entity\Task;
use App\ScheduleManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:schedule',
    description: 'Generates a schedule for the planned activities'
)]
class ScheduleActivities extends Command
{
    const DATE_NOTIME = 'Y-m-d';
    const CONTRACTED_SERVICES_VIEW = 'contracted_service_extd';

    protected \DateTimeInterface $from;
    protected \DateTimeInterface $to;
//    protected \DateTimeZone $timezone;
    protected EntityManagerInterface $entityManager;
    protected Connection $connection;
    protected ValidatorInterface $validator;
    protected ScheduleManagerFactory $scheduleManagerFactory;
    protected OutputInterface $output;
    protected InputInterface $input;

    protected ConsultantScheduleGenerator $scheduleGenerator;

    public function __construct(EntityManagerInterface $entityManager, Connection $defaultConnection,  ValidatorInterface $validator, ConsultantScheduleGenerator $scheduleGenerator, ScheduleManagerFactory $scheduleManagerFactory)
    {
        $this->entityManager = $entityManager;
        $this->connection = $defaultConnection;
        $this->validator = $validator;
        $this->scheduleGenerator = $scheduleGenerator;
        $this->scheduleManagerFactory = $scheduleManagerFactory;

        $this->from = \DateTimeImmutable::createFromFormat(DATE_ATOM,'2021-07-01T00:00:00Z');
        $this->to = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2022-07-01T00:00:00Z');

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $output->writeln(sprintf('Scheduling activities from %s to %s', $this->from->format(DATE_RFC3339), $this->to->format(DATE_RFC3339)));

        $schedule = new Schedule($this->from, $this->to);
        $manager = $this->scheduleManagerFactory->createScheduleManager($schedule);

        foreach ($this->entityManager->getRepository(Consultant::class)->findAll() as $consultant) {
            /** @var Consultant $consultant */
            $this->scheduleGenerator->setOutput($this->output);
            $consultantSchedule = $this->scheduleGenerator->generateSchedule($consultant, $this->from, $this->to);

//            $this->entityManager->persist($consultantSchedule);
//            $this->entityManager->flush();
            $manager->merge($consultantSchedule);

            $this->output->writeln(sprintf("<info>Schedule for %s</info> %s", $consultant->getName(), $consultantSchedule->getStats()));
        }

        foreach ($schedule->getTasks() as $task) {
            /** @var Task $task */
            if ($task->getSchedule() !== $schedule)
                throw new \LogicException('Task does not belong to schedule');

//            $this->entityManager->persist($task);
        }

        if (count($errors = $this->validator->validate($schedule)) > 0)
            throw new \Exception("Cannot validate schedule ". $errors);

        $this->output->writeln("<info>Generated schedule</info> {$manager->getStats()}");

        $this->entityManager->persist($schedule);
        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
