<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\UserRepository;
use App\State\UserRegistrationProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ApiResource(
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
    operations: [
        new Get(),
        new GetCollection(),
        new Post(
            processor: UserRegistrationProcessor::class,
            validationContext: ['groups' => ['Default', 'user:create']]
        ),
        new Put(),
        new Patch(),
        new Delete()
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['email' => 'exact'])]
#[UniqueEntity(fields: ['email'], message: 'Ya existe una cuenta con este email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['request:read', 'user:read', 'bid:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['user:read', 'user:write'])]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['user:read', 'pro:read', 'bid:read', 'request:read'])]
    private array $roles = [];

    #[ORM\Column]
    #[Groups(['user:write'])]
    #[Assert\NotBlank(groups: ['user:create'])]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:write'])] 
    private ?string $fcmToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firebaseUid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['user:read'])]
    private bool $verifiedEmail = false;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: ProfessionalProfile::class, cascade: ['persist', 'remove'])]
    #[Groups(['user:read', 'request:read', 'bid:read', 'user:write'])]
    private ?ProfessionalProfile $professionalProfile = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: ClientProfile::class, cascade: ['persist', 'remove'])]
    #[Groups(['user:read', 'request:read', 'bid:read', 'user:write'])]
    private ?ClientProfile $clientProfile = null;

    /** @var Collection<int, Review> */
    #[ORM\OneToMany(mappedBy: 'target', targetEntity: Review::class)]
    private Collection $reviewsReceived;

    /** @var Collection<int, Notification> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Notification::class)]
    private Collection $notifications;

    public function __construct()
    {
        $this->roles = ['ROLE_USER'];
        $this->reviewsReceived = new ArrayCollection();
        $this->notifications = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getFcmToken(): ?string
    {
        return $this->fcmToken;
    }

    public function setFcmToken(?string $fcmToken): static
    {
        $this->fcmToken = $fcmToken;
        return $this;
    }

    public function getFirebaseUid(): ?string
    {
        return $this->firebaseUid;
    }

    public function setFirebaseUid(?string $firebaseUid): static
    {
        $this->firebaseUid = $firebaseUid;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function isVerifiedEmail(): bool
    {
        return $this->verifiedEmail;
    }

    public function setVerifiedEmail(bool $verifiedEmail): static
    {
        $this->verifiedEmail = $verifiedEmail;
        return $this;
    }

    public function isVerifiedPhone(): bool
    {
        $clientVerified = $this->clientProfile?->isVerifiedPhone() ?? false;
        $proVerified = $this->professionalProfile?->isVerifiedPhone() ?? false;

        return $clientVerified || $proVerified;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
    }

    public function getProfessionalProfile(): ?ProfessionalProfile
    {
        return $this->professionalProfile;
    }

    public function setProfessionalProfile(ProfessionalProfile $professionalProfile): self
    {
        if ($professionalProfile->getUser() !== $this) {
            $professionalProfile->setUser($this);
        }

        $this->professionalProfile = $professionalProfile;

        return $this;
    }

    public function getClientProfile(): ?ClientProfile
    {
        return $this->clientProfile;
    }

    public function setClientProfile(ClientProfile $clientProfile): self
    {
        if ($clientProfile->getUser() !== $this) {
            $clientProfile->setUser($this);
        }

        $this->clientProfile = $clientProfile;

        return $this;
    }

    #[Groups(['user:read', 'request:read', 'bid:read', 'question:read'])]
    public function getFullName(): ?string
    {
        return $this->clientProfile?->getFullName() ?? $this->professionalProfile?->getFullName();
    }

    /**
     * Fecha hasta la cual el profesional tiene el plan activo (si aplica).
     * Delegate de professionalProfile para acceso directo desde el front.
     */
    #[Groups(['user:read'])]
    public function getPaidThroughAt(): ?\DateTimeImmutable
    {
        return $this->professionalProfile?->getPaidThroughAt();
    }

    /**
     * Si la suscripción Stripe del profesional está programada para cancelar al final del periodo.
     * Fuente de verdad para el front (login /me, etc.): true = mostrar estado "cancelada", false = activa.
     */
    #[Groups(['user:read'])]
    public function getSubscriptionCancelAtPeriodEnd(): bool
    {
        return $this->professionalProfile?->isSubscriptionCancelAtPeriodEnd() ?? false;
    }

    /**
     * @return Collection<int, Review>
     */
    public function getReviewsReceived(): Collection
    {
        return $this->reviewsReceived;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }
}