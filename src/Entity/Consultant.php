<?php

namespace App\Entity;

use App\Repository\ConsultantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ConsultantRepository::class)
 */
#[Assert\EnableAutoMapping]
class Consultant implements UserInterface, \Stringable
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

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\Email]
    private string $email;

    /**
     * @ORM\Column(type="string", length=255, unique=true, nullable=true)
     */
    private string $authCode;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getAuthCode(): string
    {
        return $this->authCode;
    }

    public function setAuthCode(string $code): static
    {
        $this->authCode = $code;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    //region UserInterface

    /**
     * @ORM\Column(type="simple_array")
     */
    private array $roles;

    public function getRoles(): ?array
    {
        return $this->roles;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword()
    {
        return null;
    }

    public function getSalt()
    {
        return null;
    }

    public function eraseCredentials()
    {
    }

    public function getUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getUserIdentifier(): string
    {
        return $this->getName();
    }

    //endregion UserInterface
}
