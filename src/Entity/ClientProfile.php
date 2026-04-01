<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use App\State\ClientProfileOwnerProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['client:read']]),
        new Patch(
            inputFormats: ['json' => ['application/merge-patch+json', 'application/json']],
            processor: ClientProfileOwnerProcessor::class,
            denormalizationContext: ['groups' => ['client:write']],
            security: "object.getUser() == user"
        ),
        new Put(
            processor: ClientProfileOwnerProcessor::class,
            denormalizationContext: ['groups' => ['client:write']],
            security: "object.getUser() == user"
        )
    ]
)]
class ClientProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['client:read', 'user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['request:read', 'user:read', 'user:write', 'client:write', 'bid:read'])]
    private ?string $fullName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:read', 'client:write', 'user:read', 'request:read', 'bid:read'])]
    #[Assert\Length(max: 20)]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['client:read', 'user:read'])]
    private bool $verifiedPhone = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:read', 'client:write', 'user:read', 'request:read', 'bid:read'])]
    private ?string $avatar = null;

    #[ORM\Column(name: 'rating', type: 'float', nullable: true)]
    #[Groups(['client:read', 'user:read', 'request:read', 'bid:read'])]
    private ?float $rating = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['client:read', 'user:read', 'request:read', 'bid:read'])]
    private int $reviewCount = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['client:read', 'client:write'])] 
    private bool $notifyRequestActivity = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['client:read', 'client:write'])] 
    private bool $notifyBidActivity = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    #[Groups(['client:read', 'client:write'])] 
    private bool $notifyReviews = true;

    #[ORM\OneToOne(inversedBy: 'clientProfile', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['client:read', 'request:read', 'bid:read'])]
    private ?User $user = null;

    /** @var Collection<int, Request> */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Request::class)]
    #[Groups(['client:read'])]
    private Collection $requests;

    public function __construct()
    {
        $this->requests = new ArrayCollection();
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
    public function getRequests(): Collection
    {
        return $this->requests;
    }
}
