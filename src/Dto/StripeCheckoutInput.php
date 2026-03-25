<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class StripeCheckoutInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['SOLVER', 'PRO'])]
    public string $tier;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public int $professionalProfileId;

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '#^https?://[^\s]+$#',
        message: 'Debe ser una URL válida (http:// o https://).'
    )]
    public string $successUrl;

    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '#^https?://[^\s]+$#',
        message: 'Debe ser una URL válida (http:// o https://).'
    )]
    public string $cancelUrl;
}
