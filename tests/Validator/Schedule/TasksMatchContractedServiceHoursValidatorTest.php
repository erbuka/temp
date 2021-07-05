<?php


namespace App\Tests\Validator\Schedule;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\Validator\Schedule\MatchContractedServiceHours;
use App\Validator\Schedule\MatchContractedServiceHoursValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

class TasksMatchContractedServiceHoursValidatorTest extends ConstraintValidatorTestCase
{
    /**
     * @dataProvider contractedServiceProvider
     */
    public function testTotalHoursMatch(ContractedService $cs) {
        $constraint = new MatchContractedServiceHours();

        $schedule = new Schedule(new \DateTime('2022-01-01T00:00:00Z'), new \DateTime('2023-01-01T00:00:00Z'));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(true)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P1M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P1MT{$cs->getHoursOnPremises()}H")))
        );
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(false)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P1M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P1MT{$cs->getHoursRemote()}H")))
        );

        $this->validator->validate($schedule, $constraint);
        $this->assertNoViolation();
    }

    /**
     * @dataProvider contractedServiceProvider
     */
    public function testRemoteHoursMismatch(ContractedService $cs) {
        $constraint = new MatchContractedServiceHours();

        $schedule = new Schedule(new \DateTime('2022-01-01T00:00:00Z'), new \DateTime('2023-01-01T00:00:00Z'));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(true)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P1M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P1MT{$cs->getHoursOnPremises()}H")))
        );
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(false)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P1M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P1MT{$cs->getHours()}H")))
        );

        $this->validator->validate($schedule, $constraint);
        $violations = $this->context->getViolations();

//        $this->assertCount(1, $this->context->getViolations());
        $this->buildViolation($constraint->message)
            ->setParameter('{{ contracted_service }}', $cs)
            ->setParameter('{{ type }}', 'remote')
            ->setParameter('{{ expected }}', $cs->getHoursRemote())
            ->setParameter('{{ actual }}', $cs->getHours())
            ->assertRaised();
    }

    /**
     * @dataProvider contractedServiceProvider
     * @requires testTotalHoursMismatch
     */
    public function testPremisesHoursMismatch(ContractedService $cs) {
        $constraint = new MatchContractedServiceHours();

        $schedule = new Schedule(new \DateTime('2022-01-01T00:00:00Z'), new \DateTime('2023-01-01T00:00:00Z'));
        $hoursOnPremises = $cs->getService()->getHoursOnPremises() - 1;
        $hoursRemote = $cs->getService()->getHoursRemote();
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(false)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P1M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P1MT{$hoursRemote}H")))
        );
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setOnPremises(true)
            ->setStart(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval('P2M')))
            ->setEnd(\DateTime::createFromInterface($schedule->getFrom())->add(new \DateInterval("P2MT{$hoursOnPremises}H")))
        );

        $this->validator->validate($schedule, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ contracted_service }}', $cs)
            ->setParameter('{{ type }}', 'on premises')
            ->setParameter('{{ expected }}', $cs->getHoursOnPremises())
            ->setParameter('{{ actual }}', $hoursOnPremises)
            ->assertRaised();
    }


    protected function createValidator()
    {
        return new MatchContractedServiceHoursValidator();
    }

    //region Providers

    public function contractedServiceProvider() {
        $schedule = new Schedule(new \DateTime('2022-01-01T00:00:00Z'), new \DateTime('2023-01-01T00:00:00Z'));
        $contractedServices = [
            '1' => (new ContractedService())
                ->setContract((new Contract)
                    ->setRecipient((new Recipient())->setName('Recipient #1'))
                )
                ->setConsultant((new Consultant())
                    ->setName('Alberto Montresor')
                )
                ->setService((new Service())
                    ->setName('1. Direttiva acque')
                    ->setHours(32)
                    ->setHoursOnPremises(12)
                ),
            '2' => (new ContractedService())
                ->setContract((new Contract)
                    ->setRecipient((new Recipient())->setName('Recipient #2'))
                )
                ->setConsultant((new Consultant())
                    ->setName('Cugusi Mario')
                )
                ->setService((new Service())
                    ->setName('3. Zootecnica')
                    ->setHours(59)
                    ->setHoursOnPremises(12)
                ),
        ];

//        $tasks = [
//            '1' => (new Task())
//                ->setStart(new \DateTime('2022-01-01T09:00:00Z'))->setEnd(new \DateTime('2022-01-01T12:00:00Z'))
//                ->setContractedService($contractedServices['1']),
//            '2' => (new Task())
//                ->setStart(new \DateTime('2022-01-01T12:00:00Z'))->setEnd(new \DateTime('2022-01-01T14:00:00Z'))
//                ->setContractedService($contractedServices['2']),
//            '3' => (new Task())
//                ->setStart(new \DateTime('2022-01-02T14:00:00Z'))->setEnd(new \DateTime('2022-01-02T15:00:00Z'))
//                ->setContractedService($contractedServices['2']),
//        ];
//        foreach ($tasks as $t) {
//            /** @var Task $t */
//            $schedule->addTask($t);
//        }

        $fixtures = [];
        foreach ($contractedServices as $idx => $cs) {
            $fixtures["contractedService({$idx})"] = [$cs];
        }

        return $fixtures;
    }

    //endregion Providers
}
