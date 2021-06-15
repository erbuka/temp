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
     * The exact time at which the task ends.
     * This may overlap with another task's start, in which case
     * it has to be interpreted as "just before the other task starts"
     * e.g. Task[2021-10-23T08:00:00 - 2021-10-23T09:00:00] is meant to end just before 9am (08:59:59.9999999).
     *
     * @ORM\Column(type="datetime", nullable=false)
     * @CustomAssert\DateTimeUTC()
     */
    #[Assert\NotNull]
    private \DateTimeInterface $end;

    /**
     * @ORM\Column(type="boolean")
     */
    #[Assert\NotNull]
    private bool $onPremises;

    /**
     * @ORM\ManyToOne(targetEntity=ContractedService::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private ContractedService $contractedService;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=150)
     */
    #[Assert\NotBlank]
    private string $consultantName;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=150)
     */
    #[Assert\NotBlank]
    private string $recipientName;

    /**
     * Meant to be used when directly querying the database via SQL.
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    private string $serviceName;

    /**
     * @ORM\ManyToOne(targetEntity=Schedule::class, inversedBy="tasks")
     * @ORM\JoinColumn(nullable=false)
     */
    private Schedule $schedule;

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
        $this
            ->setConsultant($cs->getConsultant())
            ->setRecipient($cs->getRecipient())
            ->setService($cs->getService());

        return $this;
    }

    public function getRecipient(): Recipient
    {
        return $this->contractedService->getRecipient();
    }

    private function setRecipient(Recipient $recipient): self
    {
        $this->recipientName = $recipient->getName();

        return $this;
    }

    public function getConsultant(): Consultant
    {
        return $this->contractedService->getConsultant();
    }

    private function setConsultant(Consultant $consultant): self
    {
        $this->consultantName = $consultant->getName();

        return $this;
    }

    public function getService(): Service
    {
        return $this->contractedService->getService();
    }

    private function setService(Service $service): self
    {
        $this->serviceName = $service->getName();

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
