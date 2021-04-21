<?php


namespace App\Command;


use App\Entity\Consultant;
use App\Repository\ConsultantRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScheduleActivities extends Command
{
    protected static $defaultName = 'app:schedule-activities';

    protected \DateTimeInterface $from;
    protected \DateTimeInterface $to;
    protected \DateTimeZone $timezone;
    protected EntityManagerInterface $entityManager;
    protected Connection $rawConnection;
    protected OutputInterface $output;
    protected InputInterface $input;

    public function __construct(EntityManagerInterface $entityManager, Connection $rawConnection)
    {
        $this->entityManager = $entityManager;
        $this->rawConnection = $rawConnection;

        $this->timezone = new \DateTimeZone('Europe/Rome');
        $this->from = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            '2021-03-01 00:00:00',
            $this->timezone
        );
        $this->to = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            '2022-02-28 23:59:59',
            $this->timezone
        );

        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Generates a schedule for the planned activities');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $em = $this->entityManager;

        $output->writeln(sprintf('Scheduling activities from %s to %s', $this->from->format(DATE_RFC3339), $this->to->format(DATE_RFC3339)));

        // For each consultant
        $sql = "
SELECT consultant, SUM(hours) as hours_total
FROM company_consultant_activity
GROUP BY consultant
HAVING hours_total <= ". 250 * 10 ."
ORDER BY hours_total DESC
        ";

        foreach ($this->rawConnection->executeQuery($sql, ['hours_cap' => 250*10], ['hours_cap' => Types::INTEGER])->iterateAssociative() as ['consultant' => $name, 'hours_total' => $total]) {
            $this->output->writeln("Allocating {$total} hours for {$name}");

            $consultant = $this->entityManager->getRepository(Consultant::class)->findOneBy(['name' => $name]);
            assert($consultant, "Cannot find consultant {$name}");

        }

        return Command::SUCCESS;
    }

    /**
     * (consultant, recipient, hours, activity_type)
     */
    protected function getConsultingContracts() {

    }

    // TODO command to load activities "codifica conagrivet"
}
