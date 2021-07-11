<?php


namespace App\Tests\Controller\Api;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use App\Tests\DoctrineTestCase;
use Flow\JSONPath\JSONPath;
use Helmich\JsonAssert\JsonAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class TaskControllerTest extends WebTestCase
{
    use DoctrineTestCase;
    use JsonAssertions;

    const TASK_ENDPOINT = '/api/v1/tasks';

    //region Delay task

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayTaskLowerBound(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = static::getClosestBusinessDay();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('08:00'))
            ->setEnd($day->modify('10:00'))
            ->setOnPremises(false));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT."/{$t1->getId()}/delay", [
            'before' => static::getClosestBusinessDay($day->modify('+1days '.ScheduleManager::DAY_END))->format(DATE_RFC3339),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        static::assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(static::getClosestBusinessDay($day->modify('+1day'))->modify(ScheduleManager::DAY_START), $t1->getStart(), "Remote task not delayed by 1 day");
        $this->assertLessThanOrEqual(static::getClosestBusinessDay($day->modify('+1day'))->modify(ScheduleManager::DAY_END), $t1->getEnd(), "Delayed task not delayed by 1 day");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayOnPremisesTaskLowerBound(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = static::getClosestBusinessDay();

        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('14:00'))
            ->setEnd($day->modify('16:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT."/{$t2->getId()}/delay", [
            'before' => static::getClosestBusinessDay(new \DateTime('+4days '.ScheduleManager::DAY_END))->format(DATE_RFC3339),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        static::assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(static::getClosestBusinessDay(new \DateTime('+4days '.ScheduleManager::DAY_START)), $t2->getStart(), "On premises task not delayed by 4 day");
        $this->assertLessThanOrEqual(static::getClosestBusinessDay(new \DateTime('+4days '.ScheduleManager::DAY_END)), $t2->getEnd(), "On premises task not delayed by 4 day");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayTaskInvalidLowerBound(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = static::getClosestBusinessDay();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($t1s = static::getClosestBusinessDay($day->modify('+7day 08:00')))
            ->setEnd($t1s->modify('16:00'))
            ->setOnPremises(false));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($t2s = static::getClosestBusinessDay($day->modify('08:00')))
            ->setEnd($t2s->modify('16:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT."/{$t1->getId()}/delay", [
            'after' => $t1->getEnd()->modify('-1hour')->format(DATE_RFC3339),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();

        $this->assertFalse($resp->isSuccessful());
        $this->assertMatchesRegularExpression('/after.+ must be after task end/i', $resp->getContent());

        $client->request('POST', static::TASK_ENDPOINT."/{$t2->getId()}/delay", [
            'after' => (new \DateTime('+3days'))->format(DATE_RFC3339),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();

        $this->assertFalse($resp->isSuccessful());
        $this->assertMatchesRegularExpression('/at least 4 days/i', $resp->getContent());
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayTaskFitsDayFreeSlots(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = static::getClosestBusinessDay();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($t1s = static::getClosestBusinessDay($day->modify('+10day 08:00')))
            ->setEnd($t1s->modify('16:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($t2s = static::getClosestBusinessDay($day->modify('+14day 09:00')))
            ->setEnd($t2s->modify('10:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT."/{$t1->getId()}/delay", [
            'after' => $t2->getStart()->setTime(8, 0)->format(DATE_RFC3339),
            'before' => $t2->getStart()->setTime(18, 0)->format(DATE_RFC3339),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);

        $resp = $client->getResponse();
        $body = $resp->getContent();

        static::assertResponseIsSuccessful();
        $this->assertEquals($t2->getEnd(), $t1->getStart(), "Delayed task start is wrong");
        $this->assertEquals($t2->getEnd()->modify('+8 hours'), $t1->getEnd(), "Delayed task end is wrong");
    }

    //endregion Delay task

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testScheduleOnPremisesTaskOnFreeSlots(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = $this->getClosestBusinessDay();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('+2weeks 08:00'))
            ->setEnd($day->modify('+2weeks 10:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('+1weeks 08:00'))
            ->setEnd($day->modify('+1weeks 16:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT, [
            'start' => $day->modify('+4 days 10:00')->format(DATE_RFC3339),
            'end' => $day->modify('+4days 18:00')->format(DATE_RFC3339),
            'onPremises' => true,
            'schedule' => $schedule->getId(),
            'contractedService' => $contract->getContractedServices()->first()->getId(),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        $this->assertEquals(Response::HTTP_CREATED, $resp->getStatusCode());
        $this->assertNotNull($t1->getDeletedAt(), "Task1 should be completely consumed and therefore removed");
        $this->assertEquals(2, $t2->getHours(), "Source task not shrunk");
        /** @var Task $created */
        $id = (new JSONPath(json_decode($body)))->find('$.id')->first();
        $created = static::find(Task::class, $id);
        $this->assertEquals(8, $created->getHours(), "Created task hours mismatch");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testScheduleOnPremisesTaskShouldNotUseDifferentContractedServices(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = $this->getClosestBusinessDay();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->last())
            ->setStart($t1s = $day->modify('+2weeks 08:00'))
            ->setEnd($t1e = $day->modify('+2weeks 10:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT, [
            'start' => $day->modify('+4 days 10:00')->format(DATE_RFC3339),
            'end' => $day->modify('+4days 12:00')->format(DATE_RFC3339),
            'onPremises' => true,
            'schedule' => $schedule->getId(),
            'contractedService' => $contract->getContractedServices()->first()->getId(),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        $this->assertFalse($resp->isSuccessful());
        $this->assertEquals(Response::HTTP_CONFLICT, $resp->getStatusCode());
        $this->assertEquals($t1s, $t1->getStart(), "Task start should not be modified");
        $this->assertEquals($t1e, $t1->getEnd(), "Task end should not be modified");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testScheduleOnPremisesTaskShouldNotUseEarlyTasks(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = $this->getClosestBusinessDay();
        if ($day->format(ScheduleManager::DATE_DAYHASH) != (new \DateTime())->format(ScheduleManager::DATE_DAYHASH))
            $this->markTestSkipped("Can only be executed on a business day");

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('10:00'))
            ->setEnd($day->modify('18:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($day->modify('+7days 14:00'))
            ->setEnd($day->modify('+7days 18:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT, [
            'start' => $day->modify('+4 days 10:00')->format(DATE_RFC3339),
            'end' => $day->modify('+4days 18:00')->format(DATE_RFC3339),
            'onPremises' => true,
            'schedule' => $schedule->getId(),
            'contractedService' => $contract->getContractedServices()->first()->getId(),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        $this->assertFalse($resp->isSuccessful());
        $this->assertNull($t1->getDeletedAt(), "Task must not be consumed because it cannot be delayed");
        $this->assertNotNull($t2->getDeletedAt(), "Task is expected to be completely consumed and therefore removed");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testScheduleOnPremisesTaskOnAllocatedSlots(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $day = $this->getClosestBusinessDay(new \DateTime('+4days '.ScheduleManager::DAY_START));
        $tomorrow = static::getClosestBusinessDay(new \DateTime('+1day ' . ScheduleManager::DAY_START));

        $manager->addTask($tsource = (new Task) // source task scheduled for tomorrow. Today's tasks are not considered for reallocation.
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($tomorrow->modify('11:00'))
            ->setEnd($tomorrow->modify('14:00'))
            ->setOnPremises(true));
        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->last())
            ->setStart($t1s = $day->modify('08:00'))
            ->setEnd($t1e = $day->modify('10:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->last())
            ->setStart($t2s = $day->modify('11:00'))
            ->setEnd($t2e = $day->modify('13:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT, [
            'start' => ($ts = $day->modify('9:00'))->format(DATE_RFC3339),
            'end' => ($te = $day->modify('12:00'))->format(DATE_RFC3339),
            'onPremises' => true,
            'schedule' => $schedule->getId(),
            'contractedService' => $contract->getContractedServices()->first()->getId(),
        ], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();
        $body = $resp->getContent();

        $this->assertEquals(Response::HTTP_CREATED, $resp->getStatusCode());
        /** @var Task $task */
        $id = (new JSONPath(json_decode($body)))->find('$.id')->first();
        $task = static::find(Task::class, $id);
        $this->assertEquals($ts, $task->getStart(), "Created task start mismatch");
        $this->assertEquals($te, $task->getEnd(), "Created task start mismatch");
        $this->assertNotNull($tsource->getDeletedAt(), "Source task should be completely consumed");

        $this->assertGreaterThan($task->getEnd(), $t1->getStart(), "Overlapping task1 not delayed");
        $this->assertGreaterThan($task->getEnd(), $t2->getStart(), "Overlapping task2 not delayed");
    }

    //region Providers

    public function provideEmptyScheduleAndContract()
    {
        $consultant = (new Consultant())->setName('Cugusi Mario');
        $schedule = (new Schedule($from = new \DateTimeImmutable('-1 months'), $from->modify('+2 months')))
            ->setConsultant($consultant);
        $contract = (new Contract)
            ->setRecipient((new Recipient())->setName('Recipient #2'))
            ->addContractedService((new ContractedService())
                ->setConsultant($consultant)
                ->setService((new Service())
                    ->setName('3. Zootecnica')
                    ->setHours(16)
                    ->setHoursOnPremises(8)
                )
            )
            ->addContractedService((new ContractedService())
                ->setConsultant($consultant)
                ->setService((new Service())
                    ->setName('8. Innovazione e tecnologie informatiche')
                    ->setHours(34)
                    ->setHoursOnPremises(20)
                )
            )
            ->addContractedService((new ContractedService())
                ->setConsultant($consultant)
                ->setService((new Service())
                    ->setName('6. Silvicoltura')
                    ->setHours(34)
                    ->setHoursOnPremises(20)
                )
            );
        ;


        assert($schedule->getFrom() <= static::getClosestBusinessDay(), "Closest business day from today is outside schedule boundaries");
        assert($schedule->getTo() >= static::getClosestBusinessDay(), "Closest business day from today is outside schedule boundaries");

        $fixtures = [
            'schedule#1' => [$schedule, $contract]
        ];

        return $fixtures;
    }

    //endregion Providers

    protected static function getScheduleManager(Schedule $schedule): ScheduleManager {
        return static::getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
    }

    protected static function getClosestBusinessDay(\DateTimeInterface $afterOrAt = null): \DateTimeImmutable
    {
        return ScheduleManager::getClosestBusinessDay($afterOrAt);
    }
}
