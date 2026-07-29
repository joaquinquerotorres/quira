<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\ReviewRepository;
use App\State\ReviewProcessor;
use App\Validator\CleanText;
use App\Validator\NoContactInfo;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(
            denormalizationContext: ['groups' => ['review:write']],
            security: "is_granted('ROLE_USER')",
            processor: ReviewProcessor::class
        ),
    ],
    normalizationContext: ['groups' => ['review:read']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'request' => 'exact',
    'author' => 'exact',
    'target' => 'exact',
])]
#[ORM\UniqueConstraint(name: 'unique_review_per_job', columns: ['request_id', 'author_id'])]
class Review
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['review:read'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['review:read', 'review:write'])]
    private ?int $score = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['review:read', 'review:write'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['review:read', 'review:write'])]
    private ?Request $request = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['review:write'])]
    private ?User $author = null;

    #[ORM\ManyToOne(inversedBy: 'reviewsReceived')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['review:read', 'review:write'])]
    private ?User $target = null;

    #[ORM\Column]
    #[Groups(['review:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(int $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getTarget(): ?User
    {
        return $this->target;
    }

    public function setTarget(?User $target): self
    {
        $this->target = $target;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[Groups(['review:read'])]
    #[SerializedName('rating')]
    public function getRating(): int
    {
        return $this->score ?? 0;
    }

    #[Groups(['review:read'])]
    #[SerializedName('date')]
    public function getDate(): string
    {
        if ($this->createdAt === null) {
            return '';
        }
        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->createdAt);
        if ($diff->days === 0) {
            return 'Hoy';
        }
        if ($diff->days === 1) {
            return 'Ayer';
        }
        if ($diff->days < 7) {
            return "Hace {$diff->days} días";
        }
        if ($diff->days < 30) {
            $weeks = (int) floor($diff->days / 7);

            return $weeks === 1 ? 'Hace 1 semana' : "Hace {$weeks} semanas";
        }

        return $this->createdAt->format('d/m/Y');
    }

    #[Groups(['review:read'])]
    #[SerializedName('text')]
    public function getText(): ?string
    {
        return $this->comment;
    }

    #[Groups(['review:read'])]
    #[SerializedName('author')]
    public function getAuthorDisplayName(): string
    {
        return $this->displayNameForUser($this->author);
    }

    #[Groups(['review:read'])]
    #[SerializedName('targetName')]
    public function getTargetDisplayName(): string
    {
        return $this->displayNameForUser($this->target);
    }

    #[Groups(['review:read'])]
    #[SerializedName('requestTitle')]
    public function getRequestTitle(): ?string
    {
        return $this->request?->getTitle();
    }

    #[Groups(['review:read'])]
    #[SerializedName('authorIsProfessional')]
    public function isAuthorProfessional(): bool
    {
        return $this->author?->isProfessionalActor() ?? false;
    }

    private function displayNameForUser(?User $user): string
    {
        if ($user === null) {
            return 'Anónimo';
        }

        return $user->getClientProfile()?->getFullName()
            ?? $user->getProfessionalProfile()?->getFullName()
            ?? 'Anónimo';
    }
}
