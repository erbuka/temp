<?php

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass=ServiceRepository::class)
 */
#[UniqueEntity('name')]
class Service
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=255, unique=true)
     */
    #[Assert\NotBlank]
    private string $name;

    /**
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    #[Assert\Positive]
    private int $hours;

    /**
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    #[Assert\PositiveOrZero]
    private int $hoursOnPremises;

    #[Pure]
    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    #[Pure]
    public function getHours(): int
    {
        return $this->hours;
    }

    public function setHours(int $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    #[Pure]
    public function getHoursOnPremises(): int
    {
        return $this->hoursOnPremises;
    }

    public function setHoursOnPremises(int $hoursOnPremises): self
    {
        $this->hoursOnPremises = $hoursOnPremises;

        return $this;
    }

    #[Pure]
    public function getHoursRemote(): int
    {
        return $this->hours - $this->getHoursOnPremises();
    }
}
