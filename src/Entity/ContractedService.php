<?php

namespace App\Entity;

use App\Repository\ContractedServiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass=ContractedServiceRepository::class)
 * @ORM\Table(
 *     uniqueConstraints={
 *          @ORM\UniqueConstraint(name="contract_unique", columns={"contract_id", "service_id", "consultant_id"})
 *     }
 * )
 */
#[UniqueEntity(['contract', 'service', 'consultant'])]
class ContractedService
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private int $hours;

    /**
     * @ORM\ManyToOne(targetEntity=Contract::class, inversedBy="services")
     * @ORM\JoinColumn(nullable=false)
     */
    private Contract $contract;

    /**
     * @ORM\ManyToOne(targetEntity=Service::class)
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Service $service;

    /**
     * @ORM\ManyToOne(targetEntity=Consultant::class)
     * @ORM\JoinColumn(referencedColumnName="name", nullable=false)
     */
    private Consultant $consultant;

    public function getId(): int
    {
        return $this->id;
    }

    public function getHours(): ?int
    {
        return $this->hours;
    }

    public function setHours(int $hours): self
    {
        $this->hours = $hours;

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
}
