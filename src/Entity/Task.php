<?php

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Validator\Constraints as CustomAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=TaskRepository::class)
 */
class Task
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\Column(type="datetime", nullable=false)
     * @CustomAssert\DateTimeUTC()
     */
    #[Assert\NotNull]
    private \DateTimeInterface $start;

    /**
     * @ORM\Column(type="datetime", nullable=false)
     * @CustomAssert\DateTimeUTC()
     */
    #[Assert\NotNull]
    private \DateTimeInterface $end;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $onPremises;

    /**
     * @ORM\ManyToOne(targetEntity=Consultant::class)
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Consultant $consultant;

    /**
     * @ORM\ManyToOne(targetEntity=Recipient::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private Recipient $recipient;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=150)
     */
    #[Assert\NotBlank]
    private string $recipientName;

    /**
     * @ORM\ManyToOne(targetEntity=Service::class)
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Service $service;

    /**
     * @ORM\ManyToOne(targetEntity=Schedule::class, inversedBy="tasks")
     * @ORM\JoinColumn(nullable=false)
     */
    private Schedule $schedule;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): Recipient
    {
        return $this->recipient;
    }

    public function setRecipient(Recipient $recipient): self
    {
        $this->recipient = $recipient;
        $this->recipientName = $recipient->getName();

        return $this;
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

    public function getService(): Service
    {
        return $this->service;
    }

    public function setService(Service $service): self
    {
        $this->service = $service;

        return $this;
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
}
