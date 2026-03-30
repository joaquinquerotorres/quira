<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\State\ProfessionalProfileOwnerProcessor;
use App\Enum\RequestStatus;
use App\Validator\CleanText;
use App\Validator\NoContactInfo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LongitudeOne\Spatial\PHP\Types\Geometry\Point;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['pro:read']]
        ),
        new GetCollection(
            normalizationContext: ['groups' => ['pro:read']]
        ),
        new Post(
            processor: ProfessionalProfileOwnerProcessor::class,
            denormalizationContext: ['groups' => ['pro:write']],
            normalizationContext: ['groups' => ['pro:read']]
        ),
        new Patch(
            inputFormats: ['json' => ['application/merge-patch+json', 'application/json']],
            processor: ProfessionalProfileOwnerProcessor::class,
            denormalizationContext: ['groups' => ['pro:write']],
            normalizationContext: ['groups' => ['pro:read']],
            security: "object.getUser() == user"
        ),
        new Put(
            denormalizationContext: ['groups' => ['pro:write']],
            normalizationContext: ['groups' => ['pro:read']],
            security: "object.getUser() == user"
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [    
    'fullName' => 'partial',
])]
class ProfessionalProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['pro:read', 'user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['pro:read', 'pro:write', 'user:read', 'request:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $fullName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['pro:read', 'pro:write', 'user:read'])]
    private ?string $taxId = null;

    #[ORM\Column(options: ["default" => false])]
    #[Groups(['pro:read', 'user:read'])]
    private bool $verifiedTaxId = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['pro:read', 'pro:write', 'user:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $bio = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['pro:read', 'pro:write', 'user:read', 'bid:read'])]
    #[Assert\Length(max: 20)]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['pro:read', 'user:read'])]
    private bool $verifiedPhone = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['pro:read', 'pro:write', 'user:read', 'request:read', 'bid:read'])]
    private ?string $avatar = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['pro:read', 'pro:write', 'user:read', 'request:read'])] 
    private ?string $address = null;

    #[ORM\Column(type: 'point', nullable: true)]
    #[Groups(['pro:read', 'pro:write'])]
    private ?Point $locationPoint = null;

    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    #[Groups(['pro:read', 'pro:write', 'user:read'])]
    private int $serviceRadiusKm = 30;

    #[ORM\Column(type: Types::JSON)]
    #[Groups(['pro:read', 'pro:write', 'user:read', 'request:read'])]
    private array $skills = [];

    #[ORM\Column(options: ["default" => false])]
    #[Groups(['pro:read', 'user:read'])]
    private bool $isVerified = false;

    /**
     * Fecha hasta la cual el profesional tiene el plan activo (trial gratuito o suscripción pagada).
     */
    #[ORM\Column(name: 'paid_through_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['pro:read', 'user:read'])]
    private ?\DateTimeImmutable $paidThroughAt = null;

    /**
     * Si la suscripción en Stripe tiene cancel_at_period_end = true.
     * Fuente de verdad para el front: cuando existe, oculta/muestra estado "cancelada".
     */
    #[ORM\Column(name: 'subscription_cancel_at_period_end', type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['pro:read', 'user:read'])]
    private bool $subscriptionCancelAtPeriodEnd = false;

    #[Groups(['pro:write'])]
    private ?string $tierRequested = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[Groups(['pro:read', 'user:read', 'request:read', 'bid:read'])]
    private ?float $rating = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['pro:read', 'user:read', 'request:read', 'bid:read'])]
    private int $reviewCount = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['pro:read', 'pro:write'])] 
    private bool $notifyRequestActivity = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['pro:read', 'pro:write'])]
    private bool $notifyBidActivity = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['pro:read', 'pro:write'])]
    private bool $notifyReviews = true;

    #[ORM\OneToOne(inversedBy: 'professionalProfile', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pro:read'])]
    private ?User $user = null;

    /** @var Collection<int, Request> */
    #[ORM\OneToMany(mappedBy: 'assignedProfessional', targetEntity: Request::class)]
    #[Groups(['user:read', 'pro:read'])]
    private Collection $assignedRequests;

    public function __construct()
    {
        $this->assignedRequests = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): self
    {
        $this->taxId = $taxId;

        return $this;
    }

    public function isVerifiedTaxId(): bool
    {
        return $this->verifiedTaxId;
    }

    public function setVerifiedTaxId(bool $verifiedTaxId): self
    {
        $this->verifiedTaxId = $verifiedTaxId;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function isVerifiedPhone(): bool
    {
        return $this->verifiedPhone;
    }

    public function setVerifiedPhone(bool $verifiedPhone): self
    {
        $this->verifiedPhone = $verifiedPhone;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): self
    {
        $this->avatar = $avatar;

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
        }

        return $this;
    }

    public function getServiceRadiusKm(): int
    {
        return $this->serviceRadiusKm;
    }

    public function setServiceRadiusKm(int $serviceRadiusKm): self
    {
        $this->serviceRadiusKm = $serviceRadiusKm;

        return $this;
    }

    public function getSkills(): array
    {
        return $this->skills;
    }

    public function setSkills(array $skills): self
    {
        $this->skills = $skills;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getPaidThroughAt(): ?\DateTimeImmutable
    {
        return $this->paidThroughAt;
    }

    public function setPaidThroughAt(?\DateTimeImmutable $paidThroughAt): self
    {
        $this->paidThroughAt = $paidThroughAt;

        return $this;
    }

    public function isSubscriptionCancelAtPeriodEnd(): bool
    {
        return $this->subscriptionCancelAtPeriodEnd;
    }

    public function setSubscriptionCancelAtPeriodEnd(bool $subscriptionCancelAtPeriodEnd): self
    {
        $this->subscriptionCancelAtPeriodEnd = $subscriptionCancelAtPeriodEnd;

        return $this;
    }

    public function getTierRequested(): ?string
    {
        return $this->tierRequested;
    }

    public function setTierRequested(?string $tierRequested): self
    {
        $this->tierRequested = $tierRequested;

        return $this;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function setRating(?float $rating): self
    {
        $this->rating = $rating;

        return $this;
    }

    public function getReviewCount(): int
    {
        // Si hay usuario asociado, calculamos el número de reviews recibidas en tiempo real.
        // Esto evita depender de que reviewCount se mantenga manualmente al crear nuevas reseñas.
        if ($this->user !== null) {
            return $this->user->getReviewsReceived()->count();
        }

        return $this->reviewCount;
    }

    public function setReviewCount(int $reviewCount): self
    {
        $this->reviewCount = $reviewCount;

        return $this;
    }

    public function getNotifyRequestActivity(): bool
    {
        return $this->notifyRequestActivity;
    }

    public function setNotifyRequestActivity(bool $notifyRequestActivity): self
    {
        $this->notifyRequestActivity = $notifyRequestActivity;

        return $this;
    }

    public function getNotifyBidActivity(): bool
    {
        return $this->notifyBidActivity;
    }

    public function setNotifyBidActivity(bool $notifyBidActivity): self
    {
        $this->notifyBidActivity = $notifyBidActivity;

        return $this;
    }

    public function getNotifyReviews(): bool
    {
        return $this->notifyReviews;
    }

    public function setNotifyReviews(bool $notifyReviews): self
    {
        $this->notifyReviews = $notifyReviews;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, Request>
     */
    public function getAssignedRequests(): Collection
    {
        return $this->assignedRequests;
    }

    public function addAssignedRequest(Request $assignedRequest): self
    {
        if (!$this->assignedRequests->contains($assignedRequest)) {
            $this->assignedRequests->add($assignedRequest);
            $assignedRequest->setAssignedProfessional($this);
        }

        return $this;
    }

    public function removeAssignedRequest(Request $assignedRequest): self
    {
        if ($this->assignedRequests->removeElement($assignedRequest)) {
            if ($assignedRequest->getAssignedProfessional() === $this) {
                $assignedRequest->setAssignedProfessional(null);
            }
        }

        return $this;
    }

    #[Groups(['pro:read'])]
    public function getCompletedJobs(): int
    {
        $count = 0;
        foreach ($this->assignedRequests as $request) {
            if ($request->getStatus() === RequestStatus::COMPLETED) {
                ++$count;
            }
        }

        return $count;
    }

    #[Groups(['pro:read'])]
    public function getReviews(): array
    {
        if ($this->user === null) {
            return [];
        }

        $result = [];
        foreach ($this->user->getReviewsReceived() as $review) {
            $createdAt = $review->getCreatedAt();
            $result[] = [
                'id' => $review->getId(),
                'score' => $review->getScore(),
                'comment' => $review->getComment(),
                'authorName' => method_exists($review, 'getAuthorDisplayName') ? $review->getAuthorDisplayName() : null,
                'createdAt' => $createdAt ? $createdAt->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return $result;
    }
}