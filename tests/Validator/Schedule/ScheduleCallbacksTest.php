<?php


namespace App\Tests\Validator\Schedule;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use Symfony\Component\Validator\Constraints\NotNullValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;

class ScheduleCallbacksTest extends ConstraintValidatorTestCase
{
    /**
     * @dataProvider scheduleAndContractedServiceProvider
     */
    public function testContractedServiceExcessHoursPerDay(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $this->markTestSkipped('Contracted service daily hours validation deprecated');

        $from = $schedule->getFrom();
        $manager = new ScheduleManager($schedule);

        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(9, 0))
            ->setEnd(($from->setTime(11, 0)))
            ->setOnPremises(true));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(11, 0))
            ->setEnd($from->setTime(13, 0))
            ->setOnPremises(true));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(15, 0))
            ->setEnd($from->setTime(18, 0))
            ->setOnPremises(false));

        $manager->reloadTasks();

        $schedule->validateContractedServicesDailyHours($this->context);

        $this->buildViolation($schedule->violationMessageContractedServiceExcessDailyHours)
            ->setParameter('{{ cs }}', $cs)
            ->setParameter('{{ hours }}', 7)
            ->setParameter('{{ day }}', $from->format(ScheduleManager::DATE_NOTIME))
            ->assertRaised();
    }

    /**
     * @dataProvider scheduleAndContractedServiceProvider
     */
    public function testOverlappingTasksViolation(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();

        // Main task to be overlapped
        $schedule->addTask($tMain = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(11, 0))
            ->setEnd(($from->setTime(14, 0)))
            ->setOnPremises(true));

        // INSIDE TASKS
        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(11, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(false));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(12, 0))
            ->setEnd($from->setTime(13, 0))
            ->setOnPremises(true));
        $schedule->addTask($t3 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(13, 0))
            ->setEnd($from->setTime(14, 0))
            ->setOnPremises(false));

        // OUTSIDE TASKS
        $schedule->addTask($t4 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(false));
        $schedule->addTask($t5 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(13, 0))
            ->setEnd($from->setTime(15, 0))
            ->setOnPremises(false));


        $manager->reloadTasks();

        $manager->detectOverlappingTasks($this->context);

        $violations = $this->context->getViolations();
//        var_dump($violations);

        $this
            // INSIDE
            ->buildViolation(ScheduleManager::VIOLATION_TASKS_OVERLAPPING)
            ->setParameter('{{ task_overlapped }}', (string) $t4)
            ->setParameter('{{ task }}', (string) $tMain)
            ->buildNextViolation(ScheduleManager::VIOLATION_TASKS_OVERLAPPING)
            ->setParameter('{{ task_overlapped }}', (string) $t4)
            ->setParameter('{{ task }}', (string) $t1)

            ->buildNextViolation(ScheduleManager::VIOLATION_TASKS_OVERLAPPING)
            ->setParameter('{{ task_overlapped }}', (string) $tMain)
            ->setParameter('{{ task }}', (string) $t2)
            ->buildNextViolation(ScheduleManager::VIOLATION_TASKS_OVERLAPPING)
            ->setParameter('{{ task_overlapped }}', (string) $tMain)
            ->setParameter('{{ task }}', (string) $t3)
            // OUTSIDE
            ->buildNextViolation(ScheduleManager::VIOLATION_TASKS_OVERLAPPING)
            ->setParameter('{{ task_overlapped }}', (string) $tMain)
            ->setParameter('{{ task }}', (string) $t5)

            ->assertRaised();
    }

    /**
     * @dataProvider scheduleAndContractedServiceProvider
     */
    public function testDiscontinuousTask(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();

        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(11, 0))
            ->setEnd(($from->setTime(14, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(15, 0))
            ->setEnd(($from->setTime(18, 0)))
            ->setOnPremises(true));

        $manager->reloadTasks();
        $manager->detectDiscontinuousTasks($this->context);

        $violations = $this->context->getViolations();

        $this
            // INSIDE
            ->buildViolation(ScheduleManager::VIOLATION_DISCONTINUOUS_TASK)
            ->setParameter('{{ task }}', (string) $t2)
            ->setParameter('{{ day }}', (string) $from->format(ScheduleManager::DATE_NOTIME))
            ->setParameter('{{ contracted_service }}', $cs)
            ->buildNextViolation(ScheduleManager::VIOLATION_DISCONTINUOUS_TASK)
            ->setParameter('{{ task }}', (string) $t1)
            ->setParameter('{{ day }}', (string) $from->format(ScheduleManager::DATE_NOTIME))
            ->setParameter('{{ contracted_service }}', $cs)

            ->assertRaised();
    }

    //region Providers

    public function scheduleAndContractedServiceProvider() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-06-18'), $from->modify('+1 week'));
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );

        $manager = new ScheduleManager($schedule);

        $fixtures = [
            '1' => [$manager, $schedule, $cs]
        ];

        return $fixtures;
    }


    //endregion Providers

    protected function createValidator()
    {
        // Dummy validator required by ContraintValidatorTestCase::setUp()
        return new NotNullValidator();
    }
}
