<?php

namespace App\Entity;

use App\Repository\ScheduleRepository;
use App\ScheduleInterface;
use App\ScheduleManager;
use App\Slot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
use App\Validator\Schedule as ScheduleAssert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Schedule validation should be performed without the use of a manager
 * so to avoid issues with out-of-sync memoized state.
 * @ORM\Entity(repositoryClass=ScheduleRepository::class)
 */
#[ScheduleAssert\TasksWithinBounds]
#[ScheduleAssert\MatchContractedServiceHours(remote: false)]
class Schedule implements ScheduleInterface
{
    public string $violationMessageContractedServiceExcessDailyHours = "Contracted service {{ cs }} has {{ hours }} > 5 hours on day {{ day }}";

    //region Persisted fields

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\Column(type="uuid")
     */
    private Uuid $uuid;

    /**
     * First day included in the period.
     *
     * @ORM\Column(name="`from`", type="datetime_immutable")
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeImmutable $from;

    /**
     * Day *after* last day included in the period
     * @ORM\Column(name="`to`", type="datetime_immutable")
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeImmutable $to;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    #[AppAssert\DateTimeUTC]
    private \DateTimeImmutable $createdAt;

    /**
     * See https://gist.github.com/pylebecq/f844d1f6860241d8b025#:~:text=What's%20the%20difference%20between%20cascade,than%20one%20object%20being%20deleted.
     * For performance considerations, see https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/working-with-associations.html#transitive-persistence-cascade-operations
     * in particular: "Cascade operations require collections and related entities to be fetched into memory"
     *
     * Orphan removal is disabled because the task is referenced by multiple entities (ScheduleCommand)
     *
     * @ORM\OneToMany(targetEntity=Task::class, mappedBy="schedule", cascade={"persist"})
     * @ORM\OrderBy({"start" = "ASC"})
     * @var Collection<int, Task>
     */
    protected Collection $tasks;

    /**
     * @ORM\ManyToOne(targetEntity=Consultant::class)
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Consultant $consultant;

    //endregion Persisted fields

    /**
     * Slots are effectively managed by ScheduleManager which sets a reference to the slots when instantiated.
     */
    public \SplFixedArray $slots;
    private Period $period;


    /**
     * Invoked only when creating new entities inside the app.
     * Never invoked by Doctrine when retrieving objcts from the database.
     *
     * @param \DateTimeInterface $toDay excluded e.g. 2022-07-01T00:00:00 => 2022-06-30T23:00:00
     */
    public function __construct(\DateTimeInterface $fromDay, \DateTimeInterface $toDay)
    {
        $this->tasks = new ArrayCollection();
        $this->uuid = Uuid::v4();
        $this->period = Period::make($fromDay, $toDay, Precision::HOUR(), Boundaries::EXCLUDE_END());
        $this->from = $this->period->start();
        $this->to = $this->period->end();
        $this->createdAt = new \DateTimeImmutable();
    }

    //region Persisted fields accessors

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    /**
     */
    public function addTask(Task $task): static
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks[] = $task;
            $task->setSchedule($this);
            $task->setDeletedAt(null);
        }

        return $this;
    }

    public function removeTask(Task $task): static
    {
        if ($this->tasks->removeElement($task)) {
            $task->setDeletedAt(new \DateTime());
        }

        return $this;
    }

    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    //endregion Persisted fields accessors

    public function containsTask(Task $task): bool
    {
        return $this->tasks->contains($task);
    }

    public function getPeriod(): Period
    {
        if (!isset($this->period))
            $this->period = Period::make($this->getFrom(), $this->getTo(), Precision::HOUR(), Boundaries::EXCLUDE_END());

        return $this->period;
    }

    public function __toString(): string
    {
        return $this->getId() ?? $this->getUuid();
    }

    public function getConsultant(): Consultant
    {
        return $this->consultant;
    }

    public function setConsultant(Consultant $consultant): self
    {
        $this->consultant = $consultant;

        return $this;
    }

}
