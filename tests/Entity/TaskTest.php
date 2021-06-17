<?php


namespace App\Tests\Entity;


use App\Entity\Task;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TaskTest extends KernelTestCase
{
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
}
