<?php

namespace App\Entity;

use App\Repository\RecipientRepository;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Deprecated;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=RecipientRepository::class)
 */
#[UniqueEntity(['name', 'vatId', 'fiscalCode'])]
class Recipient
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    #[Assert\NotBlank]
    private string $name;

    /**
     * @ORM\Column(type="string", length=13, unique=true, nullable=true)
     */
    #[Assert\Length(max: 13)]
    #[Assert\Regex('/([[:alpha:]]{2})?\d{11}/i')]
    private ?string $vatId;

    /**
     * @ORM\Column(type="string", length=16, unique=true, nullable=true)
     */
    #[Assert\Length(max: 16)]
    #[Assert\Regex('/[[:alpha:][:digit:]]{16}/i')]
    private ?string $fiscalCode;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $headquarters;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getVatId(): ?string
    {
        return $this->vatId;
    }

    public function setVatId(?string $vatId): self
    {
        $this->vatId = $vatId;

        return $this;
    }

    public function getFiscalCode(): ?string
    {
        return $this->fiscalCode;
    }

    public function setFiscalCode(?string $fiscalCode): self
    {
        $this->fiscalCode = $fiscalCode;

        return $this;
    }

    public function getHeadquarters(): ?string
    {
        return $this->headquarters;
    }

    public function setHeadquarters(?string $headquarters): self
    {
        $this->headquarters = $headquarters;

        return $this;
    }
}
