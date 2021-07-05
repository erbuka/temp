<?php


namespace App\Validator\Schedule;


use App\Entity\ContractedService;
use App\Entity\Schedule;
use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class MatchContractedServiceHoursValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof MatchContractedServiceHours)
            throw new UnexpectedTypeException($constraint, MatchContractedServiceHours::class);

        if (!$value instanceof Schedule)
            throw new UnexpectedValueException($value, Schedule::class);

        $allocatedSeconds = new \SplObjectStorage();
        $allocatedSecondsOnPremises = new \SplObjectStorage();
        $allocatedSecondsRemote = new \SplObjectStorage();

        foreach ($value->getTasks() as $task) {
            /** @var Task $task */
            $cs = $task->getContractedService();

            if (!$allocatedSeconds->contains($cs))
                $allocatedSeconds->attach($cs, 0);
            if (!$allocatedSecondsOnPremises->contains($cs))
                $allocatedSecondsOnPremises->attach($cs, 0);
            if (!$allocatedSecondsRemote->contains($cs))
                $allocatedSecondsRemote->attach($cs, 0);

            $taskSeconds = $task->getEnd()->getTimestamp() - $task->getStart()->getTimestamp();
            $allocatedSeconds[$cs] += $taskSeconds;
            if ($task->isOnPremises())
                $allocatedSecondsOnPremises[$cs] += $taskSeconds;
            else
                $allocatedSecondsRemote[$cs] += $taskSeconds;
        }

        foreach ($allocatedSeconds as $cs) {
            /** @var ContractedService $cs */
            if ($allocatedSeconds[$cs] % 3600 !== 0 || $allocatedSecondsOnPremises[$cs] % 3600 !== 0) {
                throw new \RuntimeException(sprintf("%d seconds allocated for contracted service %s are not multiple of 1 hour", $allocatedSeconds[$cs], $cs));
            }

            if ($constraint->remote) {
                $hoursRemote = (int) $allocatedSecondsRemote[$cs] / 3600;
                if ($cs->getHoursRemote() !== $hoursRemote) {
                    $this->context->buildViolation($constraint->message)
                        ->setParameter('{{ contracted_service }}', $cs)
                        ->setParameter('{{ type }}', 'remote')
                        ->setParameter('{{ expected }}', $cs->getHoursRemote())
                        ->setParameter('{{ actual }}', $hoursRemote)
                        ->addViolation()
                    ;
                }
            }

            if ($constraint->onPremises) {
                $hoursOnPremises = (int) $allocatedSecondsOnPremises[$cs] / 3600;
                if ($cs->getHoursOnPremises() !== $hoursOnPremises) {
                    $this->context->buildViolation($constraint->message)
                        ->setParameter('{{ contracted_service }}', $cs)
                        ->setParameter('{{ type }}', 'on premises')
                        ->setParameter('{{ expected }}', $cs->getService()->getHoursOnPremises())
                        ->setParameter('{{ actual }}', $hoursOnPremises)
                        ->addViolation()
                    ;
                }
            }
        }
    }
}
