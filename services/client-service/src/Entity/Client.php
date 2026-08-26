<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le client reference un utilisateur (userId) qui vit dans user-service.
 * Pas de FK SQL possible (base separee) : reference logique inter-service.
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'clients')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $companyName;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $companyName, int $userId)
    {
        $this->companyName = $companyName;
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): void
    {
        $this->companyName = $companyName;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyName' => $this->companyName,
            'userId' => $this->userId,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
