<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\Category;
use App\Enum\RequestStatus;
use App\Repository\RequestRepository;
use App\State\RequestClientProcessor;
use App\State\RequestDeleteProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geometry\Point;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiProperty;
use App\Enum\RiskLevel;
use App\Validator\CleanText;
use App\Validator\NoContactInfo;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: RequestRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['request:read']]),
        new GetCollection(normalizationContext: ['groups' => ['request:read']]),
        new Patch(
            normalizationContext: ['groups' => ['request:read']], 
            denormalizationContext: ['groups' => ['request:write']]
        ),
        new Post(
            processor: RequestClientProcessor::class,
            normalizationContext: ['groups' => ['request:read']],
            denormalizationContext: ['groups' => ['request:write']],
        ),
        new Delete(
            uriTemplate: '/requests/{id}/cancel',
            processor: RequestDeleteProcessor::class
        ),
    ]
)] 
#[ApiFilter(SearchFilter::class, properties: [
    'client.user' => 'exact',   
    'status' => 'exact',        
    'category' => 'exact',      
    'title' => 'partial',       
    'description' => 'partial',  
    'riskLevel' => 'exact'
])]
#[ApiFilter(OrderFilter::class, properties: ['estimatedPriceMin', 'createdAt'], arguments: ['orderParameterName' => 'order'])] 
class Request
{
    public const PRICING_TYPE_FIXED = 'FIXED';
    public const PRICING_TYPE_RANGE = 'RANGE';
    public const PRICING_TYPE_VISIT_REQUIRED = 'VISIT_REQUIRED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['request:read', 'calendar:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['request:read', 'request:write', 'bid:read', 'calendar:read'])]
    #[Assert\NotBlank(message: "El título no puede estar vacío.")]
    #[Assert\Length(
        min: 10,
        max: 100,
        minMessage: "El título debe tener al menos {{ limit }} caracteres."
    )]
    #[CleanText]
    #[NoContactInfo]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $description = null;

    /** Texto original del cliente antes de refinar con IA; mismo límite que POST /api/predict (description). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    #[Assert\Length(max: 5000)]
    #[CleanText]
    #[NoContactInfo]
    private ?string $clientOriginalDescription = null;

    #[ORM\Column(nullable: false)]
    #[Groups(['request:read', 'request:write'])]
    #[Assert\NotNull(message: "estimatedPriceMin es obligatorio.")]
    #[Assert\GreaterThanOrEqual(value: 0, message: "estimatedPriceMin debe ser >= 0 (céntimos).")]
    private ?int $estimatedPriceMin = null;

    #[ORM\Column(nullable: false)]
    #[Groups(['request:read', 'request:write'])]
    #[Assert\NotNull(message: "estimatedPriceMax es obligatorio.")]
    #[Assert\GreaterThanOrEqual(value: 0, message: "estimatedPriceMax debe ser >= 0 (céntimos).")]
    private ?int $estimatedPriceMax = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    private ?array $aiDiagnosis = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    #[Assert\Choice(
        choices: [self::PRICING_TYPE_FIXED, self::PRICING_TYPE_RANGE, self::PRICING_TYPE_VISIT_REQUIRED],
        message: 'El tipo de pricing no es válido.'
    )]
    private ?string $pricingType = null;

    #[ORM\Column(length: 50, enumType: RequestStatus::class)]
    #[Groups(['request:read', 'request:write', 'bid:read', 'calendar:read'])]
    private RequestStatus $status = RequestStatus::PENDING;

    #[ORM\Column(length: 50, enumType: RiskLevel::class)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private RiskLevel $riskLevel = RiskLevel::LOW;

    #[ORM\Column(length: 50, enumType: Category::class)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private Category $category = Category::DIY;

    #[ORM\Column(length: 255)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    #[Assert\NotBlank(message: "Una dirección es requerida para que el profesional sepa dónde ir.")]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?string $preciseAddress = null;

    #[ORM\Column(type: 'point', nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    private ?Point $locationPoint = null;

    #[Groups(['request:write'])]
    public ?string $photoBase64 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?string $photoUrl = null;

    #[Groups(['request:write'])]
    public ?string $audioBase64 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?string $audioUrl = null;

    #[Groups(['request:write'])]
    public ?string $videoBase64 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?string $videoUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    private ?array $extraPhotoUrls = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    private ?array $extraAudioUrls = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    private ?array $extraVideoUrls = [];

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['request:read', 'request:write'])]
    #[Assert\Choice(choices: [
        'Lo antes posible',
        'Esta semana',
        'La próxima semana',
        'A convenir al aceptar la oferta',
    ], message: 'La disponibilidad deseada no es válida.')]
    private ?string $desiredExecutionTime = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['request:read', 'request:write'])] 
    private bool $isFlagged = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['request:read'])] 
    private ?string $moderationReason = null;

    #[ORM\ManyToOne(targetEntity: ClientProfile::class, inversedBy: 'requests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?ClientProfile $client = null;

    #[ORM\ManyToOne(targetEntity: ProfessionalProfile::class, inversedBy: 'assignedRequests')] 
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['request:read', 'request:write', 'bid:read'])]
    private ?ProfessionalProfile $assignedProfessional = null;

    #[ORM\Column]
    #[Groups(['request:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Bid> */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: Bid::class, orphanRemoval: true)]
    #[Groups(['request:read'])]
    private Collection $bids;

    /** @var Collection<int, VisitRequest> */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: VisitRequest::class)]
    #[Groups(['request:read'])]
    private Collection $visitRequests;

    #[ORM\OneToMany(mappedBy: 'request', targetEntity: RequestQuestion::class, orphanRemoval: true)]
    #[Groups(['request:read'])] 
    private Collection $questions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->bids = new ArrayCollection();
        $this->visitRequests = new ArrayCollection();
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
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

    public function getClientOriginalDescription(): ?string
    {
        return $this->clientOriginalDescription;
    }

    public function setClientOriginalDescription(?string $clientOriginalDescription): self
    {
        $this->clientOriginalDescription = $clientOriginalDescription;

        return $this;
    }

    public function getEstimatedPriceMin(): ?int
    {
        return $this->estimatedPriceMin;
    }

    public function setEstimatedPriceMin(?int $estimatedPriceMin): self
    {
        $this->estimatedPriceMin = $estimatedPriceMin;

        return $this;
    }

    public function getEstimatedPriceMax(): ?int
    {
        return $this->estimatedPriceMax;
    }

    public function setEstimatedPriceMax(?int $estimatedPriceMax): self
    {
        $this->estimatedPriceMax = $estimatedPriceMax;

        return $this;
    }

    public function getAiDiagnosis(): ?array
    {
        return $this->aiDiagnosis;
    }

    public function setAiDiagnosis(?array $aiDiagnosis): self
    {
        // Compatibilidad con frontend: aiDiagnosis puede llegar como {min, max}.
        // Internamente usamos las claves existentes del sistema: estimated_price_min/estimated_price_max.
        if ($aiDiagnosis !== null) {
            if (array_key_exists('min', $aiDiagnosis) && array_key_exists('max', $aiDiagnosis)) {
                $aiDiagnosis['estimated_price_min'] = $aiDiagnosis['estimated_price_min'] ?? $aiDiagnosis['min'];
                $aiDiagnosis['estimated_price_max'] = $aiDiagnosis['estimated_price_max'] ?? $aiDiagnosis['max'];
            }
        }

        $this->aiDiagnosis = $aiDiagnosis;
        $extractedPricingType = $this->extractPricingType($aiDiagnosis);
        if ($extractedPricingType !== null) {
            $this->pricingType = $extractedPricingType;
        }

        return $this;
    }

    public function getPricingType(): ?string
    {
        return $this->pricingType;
    }

    public function setPricingType(?string $pricingType): self
    {
        $pricingType = is_string($pricingType) ? strtoupper(trim($pricingType)) : null;
        if (!in_array($pricingType, [self::PRICING_TYPE_FIXED, self::PRICING_TYPE_RANGE, self::PRICING_TYPE_VISIT_REQUIRED], true)) {
            $pricingType = null;
        }
        $this->pricingType = $pricingType;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $aiDiagnosis
     */
    private function extractPricingType(?array $aiDiagnosis): ?string
    {
        if ($aiDiagnosis === null) {
            return null;
        }

        $raw = $aiDiagnosis['pricing_type'] ?? $aiDiagnosis['pricingType'] ?? null;
        if (!is_string($raw)) {
            return null;
        }

        $pricingType = strtoupper(trim($raw));
        if (!in_array($pricingType, [self::PRICING_TYPE_FIXED, self::PRICING_TYPE_RANGE, self::PRICING_TYPE_VISIT_REQUIRED], true)) {
            return null;
        }

        return $pricingType;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function setStatus(RequestStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRiskLevel(): RiskLevel
    {
        return $this->riskLevel;
    }

    public function setRiskLevel(RiskLevel $riskLevel): self
    {
        $this->riskLevel = $riskLevel;

        return $this;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getPreciseAddress(): ?string
    {
        return $this->preciseAddress;
    }

    public function setPreciseAddress(?string $preciseAddress): self
    {
        $this->preciseAddress = $preciseAddress;

        return $this;
    }

    public function getLocationPoint(): ?Point
    {
        return $this->locationPoint;
    }

    public function setLocationPoint(array|Point|null $location): self
    {
        if (is_array($location) && isset($location['latitude'], $location['longitude'])) {
            $this->locationPoint = new Point($location['longitude'], $location['latitude']);
        } elseif ($location instanceof Point) {
            $this->locationPoint = $location;
        } elseif ($location === null) {
            $this->locationPoint = null;
        }

        return $this;
    }

    public function getDesiredExecutionTime(): ?string
    {
        return $this->desiredExecutionTime;
    }

    public function setDesiredExecutionTime(?string $desiredExecutionTime): self
    {
        $this->desiredExecutionTime = $desiredExecutionTime;
        return $this;
    }

    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl;
    }

    public function setPhotoUrl(?string $photoUrl): self
    {
        $this->photoUrl = $photoUrl;
        return $this;
    }

    public function getAudioUrl(): ?string
    {
        return $this->audioUrl;
    }

    public function setAudioUrl(?string $audioUrl): self
    {
        $this->audioUrl = $audioUrl;
        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(?string $videoUrl): self
    {
        $this->videoUrl = $videoUrl;
        return $this;
    }

    public function getExtraPhotoUrls(): ?array
    {
        return $this->extraPhotoUrls ?? [];
    }

    public function setExtraPhotoUrls(?array $extraPhotoUrls): self
    {
        $this->extraPhotoUrls = $extraPhotoUrls ?? [];

        return $this;
    }

    public function getExtraAudioUrls(): ?array
    {
        return $this->extraAudioUrls ?? [];
    }

    public function setExtraAudioUrls(?array $extraAudioUrls): self
    {
        $this->extraAudioUrls = $extraAudioUrls ?? [];

        return $this;
    }

    public function getExtraVideoUrls(): ?array
    {
        return $this->extraVideoUrls ?? [];
    }

    public function setExtraVideoUrls(?array $extraVideoUrls): self
    {
        $this->extraVideoUrls = $extraVideoUrls ?? [];

        return $this;
    }

    public function getIsFlagged(): bool
    {
        return $this->isFlagged;
    }

    public function setIsFlagged(bool $isFlagged): self
    {
        $this->isFlagged = $isFlagged;

        return $this;
    }

    public function getModerationReason(): ?string
    {
        return $this->moderationReason;
    }

    public function setModerationReason(?string $moderationReason): self
    {
        $this->moderationReason = $moderationReason;

        return $this;
    }

    public function getClient(): ?ClientProfile
    {
        return $this->client;
    }

    public function setClient(?ClientProfile $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getAssignedProfessional(): ?ProfessionalProfile
    {
        return $this->assignedProfessional;
    }

    public function setAssignedProfessional(?ProfessionalProfile $assignedProfessional): self
    {
        $this->assignedProfessional = $assignedProfessional;

        return $this;
    }

    /**
     * @return Collection<int, Bid>
     */
    public function getBids(): Collection
    {
        return $this->bids;
    }

    /** Número de propuestas recibidas (chip en listados del cliente). */
    #[Groups(['request:read'])]
    public function getBidCount(): int
    {
        return $this->bids->count();
    }

    /**
     * @return Collection<int, VisitRequest>
     */
    public function getVisitRequests(): Collection
    {
        return $this->visitRequests;
    }

    public function addBid(Bid $bid): self
    {
        if (!$this->bids->contains($bid)) {
            $this->bids->add($bid);
            $bid->setRequest($this);
        }

        return $this;
    }

    public function removeBid(Bid $bid): self
    {
        if ($this->bids->removeElement($bid)) {
            if ($bid->getRequest() === $this) {
                $bid->setRequest(null);
            }
        }

        return $this;
    }

    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(RequestQuestion $question): self
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setRequest($this);
        }

        return $this;
    }

    public function removeQuestion(RequestQuestion $question): self
    {
        if ($this->questions->removeElement($question)) {
            if ($question->getRequest() === $this) {
                $question->setRequest(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[Assert\Callback]
    public function validateDescriptionOrMedia(ExecutionContextInterface $context): void
    {
        
        $hasText = !empty($this->description) || !empty($this->clientOriginalDescription);
        $hasAudio = !empty($this->audioBase64) || !empty($this->audioUrl);
        $hasVideo = !empty($this->videoBase64) || !empty($this->videoUrl);

        if (!$hasText && !$hasAudio && !$hasVideo) {
            $context->buildViolation('Debe explicar el problema: escriba una descripción, grabe un audio o suba un video.')
                ->atPath('description')
                ->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateEstimatedPriceMinMax(ExecutionContextInterface $context): void
    {
        if ($this->estimatedPriceMin === null || $this->estimatedPriceMax === null) {
            return;
        }

        if ($this->estimatedPriceMin > $this->estimatedPriceMax) {
            $context->buildViolation('estimatedPriceMin no puede ser mayor que estimatedPriceMax.')
                ->atPath('estimatedPriceMin')
                ->addViolation();
        }
    }
}