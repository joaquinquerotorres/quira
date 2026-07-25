<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\CalendarEventRepository;
use App\State\CalendarEventOwnerProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\Table(name: 'calendar_event')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_event_request_professional', columns: ['request_id', 'professional_id'])]
#[ORM\Index(name: 'idx_calendar_event_pro_starts', columns: ['professional_id', 'starts_at'])]
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['calendar:read']]),
        new Get(normalizationContext: ['groups' => ['calendar:read']]),
        new Post(
            processor: CalendarEventOwnerProcessor::class,
            denormalizationContext: ['groups' => ['calendar:write']],
            normalizationContext: ['groups' => ['calendar:read']],
            security: "is_granted('ROLE_USER')",
        ),
        new Patch(
            processor: CalendarEventOwnerProcessor::class,
            denormalizationContext: ['groups' => ['calendar:write']],
            normalizationContext: ['groups' => ['calendar:read']],
            security: "is_granted('ROLE_USER')",
        ),
        new Delete(
            processor: CalendarEventOwnerProcessor::class,
            security: "is_granted('ROLE_USER')",
        ),
    ]
)]
#[ApiFilter(DateFilter::class, properties: ['startsAt'])]
#[ApiFilter(SearchFilter::class, properties: ['request' => 'exact'])]
class CalendarEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['calendar:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Request::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['calendar:read', 'calendar:write'])]
    #[Assert\NotNull]
    private ?Request $request = null;

    #[ORM\ManyToOne(targetEntity: ProfessionalProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['calendar:read'])]
    private ?ProfessionalProfile $professional = null;

    /** Fecha y hora de comienzo del trabajo (sin hora de fin). */
    #[ORM\Column(name: 'starts_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['calendar:read', 'calendar:write'])]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['calendar:read', 'calendar:write'])]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at')]
    #[Groups(['calendar:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    #[Groups(['calendar:read'])]
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

    public function setRequest(?Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getProfessional(): ?ProfessionalProfile
    {
        return $this->professional;
    }

    public function setProfessional(?ProfessionalProfile $professional): self
    {
        $this->professional = $professional;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): self
    {
        $this->startsAt = $startsAt;
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        $this->touch();

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
}
