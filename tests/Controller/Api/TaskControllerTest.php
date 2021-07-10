<?php


namespace App\Tests\Controller\Api;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\MoveTaskCommand;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use App\Tests\DoctrineTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class TaskControllerTest extends WebTestCase
{
    use DoctrineTestCase;

    const TASK_ENDPOINT = '/api/v1/tasks';

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayOnPremisesTask(Schedule $schedule, Contract $contract) {
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $from = $manager->getFrom();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($from->modify('+10day 08:00'))
            ->setEnd($from->modify('+10day 16:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($from->modify('+14day 09:00'))
            ->setEnd($from->modify('+14day 10:00'))
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

        $this->assertEquals(Response::HTTP_NO_CONTENT, $resp->getStatusCode());
        $this->assertEquals($t2->getEnd(), $t1->getStart(), "Delayed task start is wrong");
        $this->assertEquals($t2->getEnd()->modify('+8 hours'), $t1->getEnd(), "Delayed task end is wrong");
    }

    /**
     * @dataProvider provideEmptyScheduleAndContract
     */
    public function testDelayOnPremisesTaskUnbounded(Schedule $schedule, Contract $contract) {
        $this->markTestSkipped();
        $client = static::createClient();
        $manager = static::getScheduleManager($schedule);
        $from = $manager->getFrom();

        $manager->addTask($t1 = (new Task)
            ->setContractedService($contract->getContractedServices()->first())
            ->setStart($from->modify('+7day 08:00'))
            ->setEnd($from->modify('+7day 16:00'))
            ->setOnPremises(true));

        static::persist($contract);
        static::persist($schedule);
        static::flush();

        $client->request('POST', static::TASK_ENDPOINT."/{$t1->getId()}/delay", [], [], ['HTTP_CONTENT_TYPE' => 'multipart/form-data']);
        $resp = $client->getResponse();

        $this->assertEquals(Response::HTTP_NO_CONTENT, $resp->getStatusCode());
        $this->assertGreaterThanOrEqual(new \DateTime('+4days 08:00'), $t1->getStart(), "Task default delay <= 4 days");
    }


    public function testScheduleOnPremisesTaskOnFreeSlots() {
        $this->markTestIncomplete();
    }

    public function testScheduleOnPremisesTaskOnAllocatedSlots() {
        $this->markTestIncomplete("Schedule the task on the given datetime, but not before T+4D");
    }

    //region Providers

    public function provideEmptyScheduleAndContract()
    {
        $consultant = (new Consultant())->setName('Cugusi Mario');
        $schedule = (new Schedule($from = new \DateTimeImmutable('2021-07-12'), $from->modify('+1 month')))
            ->setConsultant($consultant);
        $contract = (new Contract)
            ->setRecipient((new Recipient())->setName('Recipient #2'))
            ->addContractedService((new ContractedService())
                ->setConsultant($consultant)
                ->setService((new Service())
                    ->setName('3. Zootecnica')
                    ->setHours(24)
                    ->setHoursOnPremises(16)
                )
            )
            ->addContractedService((new ContractedService())
                ->setConsultant($consultant)
                ->setService((new Service())
                    ->setName('8. Innovazione e tecnologie informatiche')
                    ->setHours(34)
                    ->setHoursOnPremises(20)
                )
            );


        $fixtures = [
            'schedule#1' => [$schedule, $contract]
        ];

        return $fixtures;
    }

    //endregion Providers

    protected static function getScheduleManager(Schedule $schedule): ScheduleManager {
        return static::getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
    }
}
