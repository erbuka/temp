<?php

namespace App\Entity;

use App\Repository\ConsultantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ConsultantRepository::class)
 */
#[Assert\EnableAutoMapping]
class Consultant implements \Stringable
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=150)
     */
    #[Assert\NotBlank]
    private string $name;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $title;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $jobTitle;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): self
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
