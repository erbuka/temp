<?php

namespace App\Entity;

use App\Repository\ContractRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ContractRepository::class)
 */
class Contract
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity=Recipient::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private Recipient $recipient;

    /**
     * @ORM\OneToMany(targetEntity=ContractedService::class, mappedBy="contract", orphanRemoval=true)
     */
    private Collection $contractedServices;

    /**
     * @ORM\Column(type="text")
     */
    private string $notes = '';

    public function __construct()
    {
        $this->contractedServices = new ArrayCollection();
    }

    public function getId(): int
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

        return $this;
    }

    /**
     * @return Collection|ContractedService[]
     */
    public function getContractedServices(): Collection
    {
        return $this->contractedServices;
    }

    public function addContractedService(ContractedService $service): self
    {
        if (!$this->contractedServices->contains($service)) {
            $this->contractedServices[] = $service;
            $service->setContract($this);
        }

        return $this;
    }

    public function removeContractedService(ContractedService $service): self
    {
        if ($this->contractedServices->removeElement($service)) {
            // set the owning side to null (unless already changed)
            if ($service->getContract() === $this) {
                $service->setContract(null);
            }
        }

        return $this;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }
}
