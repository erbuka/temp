<?php

namespace App\Entity;

use App\Repository\ContractedServiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ContractedServiceRepository::class)
 * @ORM\Table(
 *     uniqueConstraints={
 *          @ORM\UniqueConstraint(name="contract_unique", columns={"contract_id", "service_id", "consultant_id"})
 *     }
 * )
 */
#[Assert\EnableAutoMapping]
#[UniqueEntity(['contract', 'service', 'consultant'])]
class ContractedService implements \Stringable
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity=Contract::class, inversedBy="contractedServices", cascade={"persist"})
     * @ORM\JoinColumn(nullable=false)
     */
    private Contract $contract;

    /**
     * @ORM\ManyToOne(targetEntity=Service::class, cascade={"persist"})
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Service $service;

    /**
     * @ORM\ManyToOne(targetEntity=Consultant::class, cascade={"persist"})
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Consultant $consultant;

    public function getId(): int
    {
        return $this->id;
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

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function setContract(Contract $contract): self
    {
        $this->contract = $contract;

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

    public function getRecipientName(): string
    {
        return $this->getContract()->getRecipient()->getName();
    }

    public function getRecipient(): Recipient
    {
        return $this->getContract()->getRecipient();
    }

    public function getHours(): int
    {
        return $this->getService()->getHours();
    }

    public function getHoursOnPremises(): int
    {
        return $this->getService()->getHoursOnPremises();
    }

    public function getHoursRemote(): int
    {
        return $this->getService()->getHoursRemote();
    }

    public function __toString(): string
    {
        return "({$this->getConsultant()}, {$this->getRecipient()}, {$this->getService()})";
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'recipient' => $this->getRecipientName(),
            'service' => $this->getService()->getName(),
            'hours' => $this->getHours(),
            'hours_onpremises' => $this->getHoursOnPremises(),
        ];
    }
}
