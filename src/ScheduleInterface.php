<?php


namespace App;


use App\Entity\Task;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Spatie\Period\Period;
use Symfony\Component\Uid\Uuid;

interface ScheduleInterface
{
//    public function setManager(ScheduleManager $manager): static;

//    public function getId(): ?int;

//    public function getUuid(): Uuid;

    public function getTasks(): Collection|Selectable;

    public function addTask(Task $task): static;

    public function removeTask(Task $task): static;

    public function getFrom(): \DateTimeImmutable;

    public function getTo(): \DateTimeImmutable;

    public function containsTask(Task $task): bool;

//    public function getPeriod(): Period;
}
