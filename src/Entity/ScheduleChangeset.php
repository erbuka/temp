<?php

namespace App\Entity;

use App\Repository\ScheduleChangesetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

/**
 * @ORM\Entity(repositoryClass=ScheduleChangesetRepository::class)
 */
class ScheduleChangeset
{
    //region Persisted fields
    /**
     * @ORM\Id
     * @ORM\Column(type="ulid", unique=true)
     * @ORM\GeneratedValue(strategy="CUSTOM")
     * @ORM\CustomIdGenerator(class=UlidGenerator::class)
     */
    private Ulid $id;

    /**
     * @ORM\ManyToOne(targetEntity=Schedule::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private Schedule $schedule;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime_immutable")
     */
    protected \DateTimeImmutable $createdAt;

    /**
     * @ORM\OneToMany(targetEntity=ScheduleCommand::class, mappedBy="changeset", orphanRemoval=true, cascade={"persist", "remove"})
     * @ORM\OrderBy({"order" = "ASC"})
     */
    private Collection $commands;

    //endregion Persisted fields

    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
//        $this->createdAt = new \DateTimeImmutable();
        $this->commands = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection|ScheduleCommand[]
     */
    public function getCommands(): Collection
    {
        return $this->commands;
    }

    public function addCommand(ScheduleCommand $command): self
    {
        if (!$this->commands->contains($command)) {
            $this->commands[] = $command;
            $command->setChangeset($this);
            $command->setOrder($this->commands->count());
        }

        return $this;
    }

    public function removeCommand(ScheduleCommand $command): self
    {
        if ($this->commands->removeElement($command)) {
            // set the owning side to null (unless already changed)
            if ($command->getChangeset() === $this) {
                $command->setChangeset(null);
            }
        }

        return $this;
    }
}
