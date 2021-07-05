<?php

namespace App\Entity;

use App\Repository\ScheduleCommandRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * It shall not use ScheduleManager.
 *
 * @ORM\Entity(repositoryClass=ScheduleCommandRepository::class)
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="action", type="string")
 */
abstract class ScheduleCommand
{
    //region Persisted fields
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id;

    /**
     * @ORM\Column(name="`order`", type="integer", options={"unsigned":true})
     */
    private int $order;

    /**
     * @ORM\ManyToOne(targetEntity=ScheduleChangeset::class, inversedBy="commands")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private ?ScheduleChangeset $changeset;

    //endregion Persisted fields

    private Schedule $schedule;

    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChangeset(): ?ScheduleChangeset
    {
        return $this->changeset;
    }

    public function setChangeset(?ScheduleChangeset $changeset): static
    {
        $this->changeset = $changeset;

        return $this;
    }

    public function setOrder(int $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public abstract function execute(): void;

    public abstract function undo(): void;
}
