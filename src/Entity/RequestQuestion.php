<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\RequestQuestionProcessor;
use App\Validator\CleanText;
use App\Validator\NoContactInfo;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['question:read']]
        ),
        new Post(
            processor: RequestQuestionProcessor::class,
            denormalizationContext: ['groups' => ['question:write']],
            normalizationContext: ['groups' => ['question:read']],
            security: "is_granted('ROLE_PROFESSIONAL')"
        ),
        new Patch(
            denormalizationContext: ['groups' => ['question:answer']],
            normalizationContext: ['groups' => ['question:read']],
            security: "object.getRequest().getClient().getUser() == user"
        )
    ],
    order: ['createdAt' => 'ASC']
)]
#[ApiResource(
    uriTemplate: '/requests/{id}/questions',
    operations: [new GetCollection()],
    uriVariables: [
        'id' => new Link(toProperty: 'request', fromClass: Request::class),
    ],
    normalizationContext: ['groups' => ['question:read']],
    order: ['createdAt' => 'ASC']
)]
#[ApiFilter(SearchFilter::class, properties: ['request' => 'exact'])]
class RequestQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['question:read', 'request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['question:write', 'question:read'])]
    private ?Request $request = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['question:read', 'request:read'])]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['question:read', 'question:write', 'request:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $questionText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['question:read', 'question:answer', 'request:read'])]
    #[CleanText]
    #[NoContactInfo]
    private ?string $answerText = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['question:read', 'question:answer', 'request:read'])]
    #[Assert\Count(
        max: 3,
        maxMessage: 'Puedes adjuntar como máximo {{ limit }} archivos de media en la respuesta.'
    )]
    private ?array $answerMediaUrls = [];

    #[ORM\Column]
    #[Groups(['question:read', 'request:read'])]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getQuestionText(): ?string
    {
        return $this->questionText;
    }

    public function setQuestionText(string $questionText): self
    {
        $this->questionText = $questionText;

        return $this;
    }

    public function getAnswerText(): ?string
    {
        return $this->answerText;
    }

    public function setAnswerText(?string $answerText): self
    {
        $this->answerText = $answerText;

        return $this;
    }

    public function getAnswerMediaUrls(): array
    {
        return $this->answerMediaUrls ?? [];
    }

    public function setAnswerMediaUrls(?array $answerMediaUrls): self
    {
        $this->answerMediaUrls = $answerMediaUrls;

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
}