<?php 
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ApiResource(operations: [new Get()])]
class GeminiCache
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $cacheId;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 50)]
    public string $model = 'models/gemini-2.5-flash';

    public function isValid(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable('+5 minutes');
    }
}

