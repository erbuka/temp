<?php


namespace App\Tests\Validator;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\Validator\Schedule\TasksMatchContractedServiceHours;
use App\Validator\Schedule\TasksMatchContractedServiceHoursValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

class TasksMatchContractedServiceHoursValidatorTest extends ConstraintValidatorTestCase
{
    public function testWhatever(ContractedService $cs = null) {
        if (!$cs) {
            $cs = (new ContractedService())
                ->setContract((new Contract)
                    ->setRecipient((new Recipient())->setName('Recipient #1'))
                )
                ->setConsultant((new Consultant())
                    ->setName('Ettore Del Negro')
                )
                ->setService((new Service())
                    ->setName('1. Direttiva acque')
                    ->setHoursOnPremises(1)
                    ->setHours(32)
                );
            $cs->getContract()->addContractedService($cs);
        }

        $schedule = new Schedule(new \DateTime(), new \DateTime('+1 year'));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart(new \DateTime())
            ->setEnd(new \DateTime('+6 hours'))
        );

        $this->validator->validate($schedule, new TasksMatchContractedServiceHours());
        $this->assertNoViolation();
    }

    protected function createValidator()
    {
        return new TasksMatchContractedServiceHoursValidator();
    }
}
