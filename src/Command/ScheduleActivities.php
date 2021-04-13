<?php


namespace App\Command;


use App\Entity\Consultant;
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
        $this->setAliases(['app:schedule']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(sprintf('Scheduling activities from %s to %s', $this->from->format(DATE_RFC3339), $this->to->format(DATE_RFC3339)));

//        $em = $this->entityManager->getConnection()

//        $em->createNativeQuery('SELECT FROM')

//        $stm = $this->rawConnection->executeQuery('SELECT * FROM activity');
//        $data = $stm->fetchAll();

        $consultants = $this->getConsultants();

        // Generates a schedule for each consultant

        return Command::SUCCESS;
    }

    /**
     * @return array Consultant[]
     * @throws
     */
    protected function getConsultants(): array {
        $sql = "
SELECT consultant as name, consultant_category as job_title, COUNT(DISTINCT consultant_category) as job_titles
FROM company_consultant_activity
GROUP BY consultant;";

        $res = $this->rawConnection->executeQuery($sql);

        $consultants = [];
        foreach ($res->iterateAssociative() as ['name' => $name, 'job_title' => $jobTitle, 'job_titles' => $jobTitlesCount]) {
            assert((int) $jobTitlesCount === 1, "Consultant $name has more than 1 job");

            $consultants[] = (new Consultant())
                ->setName($name)
                ->setJobTitle($jobTitle)
            ;
        }

        return $consultants;
    }

    /**
     * (consultant, recipient, hours, activity_type)
     */
    protected function getConsultingContracts() {

    }

    // TODO command to load activities "codifica conagrivet"
}
