<?php

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Validator as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=TaskRepository::class)
 */
#[Assert\EnableAutoMapping]
class Task implements \Stringable
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\Column(type="datetime")
     */
    #[AppAssert\TimeRange(from: '08:00', to: '19:00')]
    #[Assert\Expression("value < this.getEnd()", message: "Task start date is after or on end date")]
    #[Assert\Expression("value.format('w') not in [6,0]", message: "Task is on a weekend day")]
    private \DateTimeInterface $start;

    /**
     * The exact time at which the task ends.
     * This may overlap with another task's start, in which case
     * it has to be interpreted as "just before the other task starts"
     * e.g. Task[2021-10-23T08:00:00 - 2021-10-23T09:00:00] is meant to end just before 9am (08:59:59.9999999).
     *
     * @ORM\Column(type="datetime")
     */
    #[AppAssert\TimeRange(from: '08:00', to: '19:00')]
    #[Assert\Expression("value > this.getStart()", message: "Task end date is before or on start date")]
    #[Assert\Expression("value.format('w') not in [6,0]", message: "Task is on a weekend day")]
    #[Assert\Expression("value.format('Ymd') === this.getStart().format('Ymd')", message: "Task spans across multiple days")]
    private \DateTimeInterface $end;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $onPremises;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=150)
     */
    private string $consultantName;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=150)
     */
    private string $recipientName;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=255)
     */
    private string $serviceName;

    /**
     * @ORM\ManyToOne(targetEntity=Schedule::class, inversedBy="tasks")
     * @ORM\JoinColumn(nullable=false)
     */
    #[Assert\DisableAutoMapping]
    private Schedule $schedule;

    /**
     * @ORM\ManyToOne(targetEntity=ContractedService::class)
     * @ORM\JoinColumn(nullable=false)
     */
    #[Assert\DisableAutoMapping]
    private ContractedService $contractedService;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContractedService(): ContractedService
    {
        return $this->contractedService;
    }

    public function setContractedService(ContractedService $cs): self
    {
        $this->contractedService = $cs;
        $this->consultantName = $cs->getConsultant()->getName();
        $this->recipientName = $cs->getRecipientName();
        $this->serviceName = $cs->getService()->getName();

        return $this;
    }

    public function getRecipient(): Recipient
    {
        return $this->contractedService->getRecipient();
    }

    public function getConsultant(): Consultant
    {
        return $this->contractedService->getConsultant();
    }

    public function getService(): Service
    {
        return $this->contractedService->getService();
    }

    public function getStart(): \DateTimeInterface
    {
        return $this->start;
    }

    public function setStart(\DateTimeInterface $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): \DateTimeInterface
    {
        return $this->end;
    }

    public function setEnd(\DateTimeInterface $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function getOnPremises(): bool
    {
        return $this->onPremises;
    }

    public function setOnPremises(bool $onPremises): self
    {
        $this->onPremises = $onPremises;

        return $this;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function setSchedule(Schedule $schedule): self
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf("[(%s) %s %s]", $this->id ?? '', $this->getStart()->format(DATE_RFC3339), $this->getEnd()->format(DATE_RFC3339));
    }
}
