<?php


namespace App\Tests\Entity;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Tests\DoctrineTestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TaskTest extends KernelTestCase
{
    use DoctrineTestCase;

    public function testWeekendsInvalid() {
        $validator = $this->getContainer()->get('validator');
        $t = (new Task)
            ->setStart(new \DateTime('2021-06-19T10:00:00Z'))
            ->setEnd(new \DateTime('2021-06-19T12:00:00Z'))
            ->setOnPremises(false)
        ;

        $v = $validator->validate($t);

        $this->assertCount(1, $v);
        $this->assertSame($t->getStart(), $v->get(0)->getInvalidValue());
        $this->assertEquals("Task is on a weekend day", $v->get(0)->getMessage());
    }

    public function testSpansMultipleDaysInvalid() {
        $validator = $this->getContainer()->get('validator');
        $t = (new Task)
            ->setStart(new \DateTime('2021-06-16T10:00:00Z'))
            ->setEnd(new \DateTime('2021-06-17T08:00:00Z'))
            ->setOnPremises(false)
        ;

        $v = $validator->validate($t);

        $this->assertCount(1, $v);
        $this->assertSame($t->getEnd(), $v->get(0)->getInvalidValue());
        $this->assertEquals("Task spans across multiple days", $v->get(0)->getMessage());
    }

    public function testEndBeforeStartInvalid() {
        $validator = $this->getContainer()->get('validator');
        $t = (new Task)
            ->setStart(new \DateTime('2021-06-16T09:00:00Z'))
            ->setEnd(new \DateTime('2021-06-16T09:00:00Z'))
            ->setOnPremises(false)
        ;

        $v = $validator->validate($t);

        $this->assertCount(1, $v);
        $this->assertSame($t->getEnd(), $v->get(0)->getInvalidValue());
        $this->assertEquals("Task end date is before or on start date", $v->get(0)->getMessage());
    }

    public function testTaskSoftDelete() {
        $em = static::getManager();
        $schedule = new Schedule($from = new \DateTimeImmutable(), $from->modify('+1 month'));
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );
        $schedule->setConsultant($cs->getConsultant());

        $schedule->addTask($t1 = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs)
            ->setState([])
        );

        static::persist($cs->getContract()->getRecipient());
        static::persist($cs->getContract());
        static::persist($cs->getConsultant());
        static::persist($cs->getService());
        static::persist($cs);
        static::persist($schedule);
        static::flush();

        static::remove($t1);
        static::flush();

        $repo = $em->getRepository(Task::class);

        $this->assertCount(0, $repo->findAll(), "Soft-deleted task is not filtered from query results");

        static::getManager()->getFilters()->disable('softdeleteable');
        $this->assertCount(1, $repo->findAll(), "Soft-deleted task has been deleted!");
    }
}
