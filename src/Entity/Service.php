<?php

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass=ServiceRepository::class)
 */
#[UniqueEntity('name')]
class Service implements \Stringable
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=255, unique=true)
     */
    #[Assert\NotBlank]
    private string $name;

    /**
     * @ORM\Column(type="smallint", options={"unsigned":true})
     */
    #[Assert\Positive]
    private int $hours;

    /**
     * @ORM\Column(type="smallint", options={"unsigned":true})
     */
    #[Assert\Positive]
    #[Assert\Expression("value <= this.getHours()", message: 'Hours on premises >= total hours')]
    private int $hoursOnPremises;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $category;

    /**
     * @ORM\Column(type="array", nullable=true)
     */
//    #[Assert\NotBlank(message: 'Service steps must be defined')]
    private ?array $steps = [];

    /**
     * @ORM\Column(type="array", nullable=true)
     */
//    #[Assert\NotBlank(message: 'Service reasons must be defined')]
    private ?array $reasons = [];

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $expectations;

    /**
     * Date before which this service should not be performed.
     *
     * @ORM\Column(type="date", nullable=true)
     */
    #[AppAssert\DateTimeUTC]
    private ?\DateTimeInterface $fromDate;

    /**
     * Date after which this service should not be performed.
     *
     * @ORM\Column(type="date", nullable=true)
     */
    #[AppAssert\DateTimeUTC]
    private ?\DateTimeInterface $toDate;

    public function getName(): string
    {
        return $this->name;
    }
    public function getNameWithoutPrefix(): ?string
    {
        return preg_replace('/^\d+\.\s+/i', '', $this->name);
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getHours(): int
    {
        return $this->hours;
    }

    public function setHours(int $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    public function getHoursOnPremises(): int
    {
        return $this->hoursOnPremises;
    }

    public function setHoursOnPremises(int $hoursOnPremises): self
    {
        $this->hoursOnPremises = $hoursOnPremises;

        return $this;
    }

    #[Assert\Expression("value == (this.getHours() - this.getHoursOnPremises())", message: 'Remote hours added to on-premises does not match total hours')]
    public function getHoursRemote(): int
    {
        return $this->hours - $this->getHoursOnPremises();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getSteps(): ?array
    {
        return $this->steps;
    }

    public function setSteps(?array $steps): self
    {
        $this->steps = $steps;

        return $this;
    }

    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function setReasons(?array $reasons): self
    {
        $this->reasons = $reasons;

        return $this;
    }

    public function getExpectations(): string
    {
        return $this->expectations;
    }

    public function setExpectations(?string $expectations): self
    {
        $this->expectations = $expectations;

        return $this;
    }

    public function getFromDate(): ?\DateTimeInterface
    {
        return $this->fromDate;
    }

    public function setFromDate(?\DateTimeInterface $from): self
    {
        $this->fromDate = $from;

        return $this;
    }

    public function getToDate(): ?\DateTimeInterface
    {
        return $this->toDate;
    }

    public function setToDate(?\DateTimeInterface $to): self
    {
        $this->toDate = $to;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
