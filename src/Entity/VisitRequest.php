<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use App\Repository\VisitRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VisitRequestRepository::class)]
#[ORM\Table(name: 'visit_request')]
#[ORM\UniqueConstraint(name: 'uniq_visit_request_request_professional', columns: ['request_id', 'professional_id'])]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['visit:read']]),
        new GetCollection(normalizationContext: ['groups' => ['visit:read']]),
    ]
)]
class VisitRequest
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['visit:read', 'request:read', 'pro:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Request::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['visit:read'])]
    private ?Request $request = null;

    #[ORM\ManyToOne(targetEntity: ProfessionalProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['visit:read', 'request:read', 'pro:read'])]
    private ?ProfessionalProfile $professional = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_REJECTED])]
    #[Groups(['visit:read', 'request:read', 'pro:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['visit:read', 'request:read', 'pro:read'])]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    #[Groups(['visit:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    #[Groups(['visit:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getProfessional(): ?ProfessionalProfile
    {
        return $this->professional;
    }

    public function setProfessional(ProfessionalProfile $professional): self
    {
        $this->professional = $professional;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Groups(['visit:read', 'request:read'])]
    public function getProfessionalPhone(): ?string
    {
        if ($this->status !== self::STATUS_ACCEPTED) {
            return null;
        }

        return $this->professional?->getPhoneNumber();
    }
}

