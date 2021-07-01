<?php


namespace App;


use App\Entity\Task;
use Doctrine\Common\Collections\Collection;
use Spatie\Period\Period;
use Symfony\Component\Uid\Uuid;

interface ScheduleInterface
{
//    public function setManager(ScheduleManager $manager): static;

//    public function getId(): ?int;

//    public function getUuid(): Uuid;

    public function getTasks(): Collection;

    public function addTask(Task $task): static;

    public function removeTask(Task $task): static;

    public function getFrom(): \DateTimeInterface;

    public function getTo(): \DateTimeInterface;

    public function containsTask(Task $task): bool;

//    public function getPeriod(): Period;
}
