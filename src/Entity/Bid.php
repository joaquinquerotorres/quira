<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Enum\BidStatus;
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
#[ORM\UniqueConstraint(name: 'uniq_bid_request_professional', columns: ['request_id', 'professional_id'])]
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['bid:read']]),
        new Get(normalizationContext: ['groups' => ['bid:read']]),
        new Post(
            processor: BidProfessionalProcessor::class,
            denormalizationContext: ['groups' => ['bid:write']],
            normalizationContext: ['groups' => ['bid:read']],
            openapi: new OpenApiOperation(
                summary: 'Crear puja',
                description: '422: violations[] + hydra:description (JSON-LD) o Problem+JSON. Códigos estables: '
                    . 'BID_HIGH_REQUIRES_PAID_SUBSCRIPTION (propertyPath riskLevel, HIGH sin paidThroughAt vigente); '
                    . 'BID_MONTHLY_LIMIT_EXCEEDED (propertyPath monthlyBidLimit). El cliente puede mostrar message tal cual.',
            ),
        ),
        new Patch(
            name: 'accept_bid',
            uriTemplate: '/bids/{id}/accept',
            processor: BidAcceptanceProcessor::class,
            denormalizationContext: ['groups' => ['bid:accept']],
            normalizationContext: ['groups' => ['bid:read']]
        ),
        new Delete(
            uriTemplate: '/bids/{id}/withdraw',
            processor: BidWithdrawProcessor::class,
            normalizationContext: ['groups' => ['bid:read']]
        )
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['priceQuote', 'createdAt'], arguments: ['orderParameterName' => 'order'])]
class Bid
{
    public const PRICING_TYPE_FIXED = 'FIXED';
    public const PRICING_TYPE_RANGE = 'RANGE';

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

    #[ORM\Column(length: 20, options: ['default' => self::PRICING_TYPE_FIXED])]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[Assert\Choice(choices: [self::PRICING_TYPE_FIXED, self::PRICING_TYPE_RANGE], message: 'El tipo de precio de la propuesta no es válido.')]
    private string $pricingType = self::PRICING_TYPE_FIXED;

    #[ORM\Column(nullable: true)]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[Assert\GreaterThan(value: 0, message: 'priceQuoteMin debe ser mayor que 0.')]
    private ?int $priceQuoteMin = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[Assert\GreaterThan(value: 0, message: 'priceQuoteMax debe ser mayor que 0.')]
    private ?int $priceQuoteMax = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bid:read', 'bid:write', 'request:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $comment = null;

    #[ORM\Column(length: 20)]
    #[Groups(['bid:read', 'bid:write', 'bid:accept', 'request:read'])]
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

    public function getPricingType(): string
    {
        return $this->pricingType;
    }

    public function setPricingType(string $pricingType): self
    {
        $this->pricingType = strtoupper(trim($pricingType));

        return $this;
    }

    public function getPriceQuoteMin(): ?int
    {
        return $this->priceQuoteMin;
    }

    public function setPriceQuoteMin(?int $priceQuoteMin): self
    {
        $this->priceQuoteMin = $priceQuoteMin;

        return $this;
    }

    public function getPriceQuoteMax(): ?int
    {
        return $this->priceQuoteMax;
    }

    public function setPriceQuoteMax(?int $priceQuoteMax): self
    {
        $this->priceQuoteMax = $priceQuoteMax;

        return $this;
    }

}