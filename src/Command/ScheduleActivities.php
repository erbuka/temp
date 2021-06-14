<?php


namespace App\Command;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Service;
use App\Entity\Task;
use App\Entity\Schedule;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
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
    protected \DateTimeZone $timezone;
    protected EntityManagerInterface $entityManager;
    protected Connection $rawConnection;
    protected Connection $connection;
    protected ValidatorInterface $validator;
    protected OutputInterface $output;
    protected InputInterface $input;

    /** @var Service[] */
    private array $services;
    /** @var array<string, array<string, number>> */
    private array $serviceSlots; // ['service name' => ['start' => 12, 'end' => 123]

//    private Schedule $schedule;

    public function __construct(EntityManagerInterface $entityManager, Connection $rawConnection, Connection $defaultConnection,  ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->rawConnection = $rawConnection;
        $this->connection = $defaultConnection;
        $this->validator = $validator;

        $this->from = \DateTimeImmutable::createFromFormat(DATE_ATOM,'2021-07-01T00:00:00Z');
        $this->to = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2022-05-30T23:59:59Z');

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $output->writeln(sprintf('Scheduling activities from %s to %s', $this->from->format(DATE_RFC3339), $this->to->format(DATE_RFC3339)));

        $this->preloadServices();
        $schedule = new Schedule($this->from, $this->to);

        $recipientsSQL = "
SELECT recipient_id, service FROM `". static::CONTRACTED_SERVICES_VIEW ."`
WHERE consultant = :consultant
GROUP BY recipient_id, service
ORDER BY recipient_id
";

        foreach ($this->entityManager->getRepository(Consultant::class)->findAll() as $consultant) {
            /** @var Consultant $consultant */
//            if ($consultant->getName() !== 'Belelli Fiorenzo') continue;

            $consultantSchedule = new Schedule($this->from, $this->to);

            // !!! TODO for each service, $scheduler->getRandomFreeSlot($service->start, $service->end)
            // TODO check start and end bounderies, e.g. end=2021-10-01T00:00 must include somehow 2021-10-01T18:00

            // Allocate 1 slot for each (client, service)
            foreach ($this->connection->executeQuery($recipientsSQL, ['consultant' => $consultant->getName()])->iterateAssociative() as [
                'recipient_id' => $recipientId,
                'service' => $serviceName
            ]) {
                /** @var Recipient $recipient */
                $recipient = $this->entityManager->getRepository(Recipient::class)->find($recipientId);
                /** @var Service $service */
                $service = $this->entityManager->getRepository(Service::class)->find($serviceName);

                assert($recipient !== null, "Unable to lookup recipient {$recipientId}");
                assert($service !== null, "Unable to lookup service {$serviceName}");

                // Allocate recipient
                $slot = $consultantSchedule->getRandomFreeSlot($service->getFromDate(), $service->getToDate());

                // Select service by ranking all services belonging to this client

//                $services = $this->rankServices($slot->getPeriod()->start(), $recipient, $consultant);
//                assert(count($services) > 0, "Not implemented: handle the case where there are no services for (client, slot_date)");
//                $service = current($services);

                $task = new Task();
                $task->setConsultant($consultant);
                $task->setRecipient($recipient);
                $task->setService($service);
                $task->setStart($slot->getStart()); // truncated by precision
                $task->setEnd($slot->getEnd()); // truncated by precision
                $task->setOnPremises(false);

                if (count($errors = $this->validator->validate($task)) > 0) {
                    throw new \Exception("Cannot validate task ({$task->getConsultant()->getName()}, {$task->getRecipient()->getName()}, {$task->getService()->getName()}): ". $errors);
                }

                $this->entityManager->persist($task);

                $slot->addTask($task);
                $consultantSchedule->addTask($task);

//                $this->output->writeln(sprintf('[%s] Allocated slot %s to service "%s" for client "%s"',
//                    $consultant->getName(),
//                    $slot->getPeriod()->asString(),
//                    $service->getName(),
//                    $recipient->getName()
//                ));
            }

//            $this->entityManager->persist($consultantSchedule);
            $schedule->merge($consultantSchedule);

            $this->output->writeln(sprintf("<info>Schedule for %s</info> %s", $consultant->getName(), $consultantSchedule->getStats()));
        }

        $this->output->writeln("<info>Generated schedule</info> {$schedule->getStats()}");

        $this->entityManager->persist($schedule);
        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    /**
     * (consultant, recipient, hours, activity_type)
     */
    protected function getConsultingContracts() {

    }

    /**
     */
    protected function rankServices(\DateTimeInterface $date, Recipient $recipient, Consultant $consultant)
    {
        $services = [];

        $contract = $this->entityManager->getRepository(Contract::class)->findOneBy(['recipient' => $recipient]);
        assert($contract !== null);
        $contractedServices = $this->entityManager->getRepository(ContractedService::class)->findBy([
            'consultant' => $consultant,
            'contract' => $contract,
        ]);

        foreach ($contractedServices as $contractedService) {
            $service = $contractedService->getService();
            if (!$service->getDateBefore()) {
                $service->setDateBefore($this->to);
            }
            if (!$service->getDateAfter()) $service->setDateAfteR($this->from);

            $period = Period::make($service->getDateAfter(), $service->getDateBefore(), Precision::DAY());
            if (!$period->contains($date))
                continue;

            $services[] = $service;
        }

        usort($services, function (/** @var Service $a */ $a, /** @var Service $b */ $b) {
            $a_interval = $a->getToDate()->diff($a->getFromDate(), true);
            $b_interval = $b->getDateBefore()->diff($b->getDateAfter(), true);

            return $a_interval->days <=> $b_interval->days;
        });

        return $services;
    }

    private function preloadServices()
    {
        foreach ($this->entityManager->getRepository(Service::class)->findAll() as /** @var Service $service */ $service) {
            if (!$service->getToDate() || $service->getToDate() > $this->to)
                $service->setToDate($this->to);

            if (!$service->getFromDate() || $service->getFromDate() < $this->from)
                $service->setFromDate($this->from);

            $this->services[$service->getName()] = $service;
        }
    }
}
