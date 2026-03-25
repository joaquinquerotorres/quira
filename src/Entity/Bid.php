<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use App\Enum\BidStatus;
use App\Entity\VisitRequest;
use App\Repository\BidRepository;
use App\State\BidAcceptanceProcessor;
use App\State\BidProfessionalProcessor;
use App\State\BidWithdrawProcessor;
use App\Validator\CleanText;
use App\Validator\NoContactInfo;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BidRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['bid:read']]),
        new Get(normalizationContext: ['groups' => ['bid:read']]),
        new Post(
            processor: BidProfessionalProcessor::class,
            denormalizationContext: ['groups' => ['bid:write']],
            normalizationContext: ['groups' => ['bid:read']]
        ),
        new Patch(
            name: 'accept_bid',
            uriTemplate: '/bids/{id}/accept',
            processor: BidAcceptanceProcessor::class,
            denormalizationContext: ['groups' => ['bid:accept']],
            normalizationContext: ['groups' => ['bid:read']]
        ),
        new Patch(
            processor: BidWithdrawProcessor::class,
            denormalizationContext: ['groups' => ['bid:withdraw']],
            normalizationContext: ['groups' => ['bid:read']]
        )
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['priceQuote', 'createdAt'], arguments: ['orderParameterName' => 'order'])]
class Bid
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['bid:read', 'request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Request::class, inversedBy: 'bids')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['bid:read', 'bid:write'])]
    #[Assert\NotNull]
    private ?Request $request = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['bid:read', 'request:read'])]
    private ?User $professional = null;

    #[ORM\Column]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $priceQuote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $comment = null;

    #[ORM\Column(length: 20)]
    #[Groups(['bid:read', 'bid:write', 'bid:accept', 'bid:withdraw', 'request:read'])]
    private BidStatus $status = BidStatus::PENDING;

    #[ORM\Column]
    #[Groups(['bid:read', 'request:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[Assert\Choice(choices: [
        'Hoy mismo',
        'Mañana',
        'Esta semana',
        'La próxima semana',
        'En dos semanas o más',
        'A convenir al aceptar la oferta',
    ], message: 'La fecha estimada de realización no es válida.')]
    private ?string $estimatedExecutionTime = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getProfessional(): ?User
    {
        return $this->professional;
    }

    public function setProfessional(?User $professional): self
    {
        $this->professional = $professional;

        return $this;
    }

    public function getPriceQuote(): ?int
    {
        return $this->priceQuote;
    }

    public function setPriceQuote(?int $priceQuote): self
    {
        $this->priceQuote = $priceQuote;

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

    public function getStatus(): BidStatus
    {
        return $this->status;
    }

    public function setStatus(BidStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEstimatedExecutionTime(): ?string
    {
        return $this->estimatedExecutionTime;
    }

    public function setEstimatedExecutionTime(?string $estimatedExecutionTime): self
    {
        $this->estimatedExecutionTime = $estimatedExecutionTime;

        return $this;
    }

}