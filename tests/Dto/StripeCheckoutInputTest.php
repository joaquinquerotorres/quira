<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\StripeCheckoutInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class StripeCheckoutInputTest extends TestCase
{
    private function createValidator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAcceptsLocalhostUrls(): void
    {
        $input = new StripeCheckoutInput();
        $input->tier = 'SOLVER';
        $input->professionalProfileId = 1;
        $input->successUrl = 'http://localhost:3000/success';
        $input->cancelUrl = 'https://localhost:8000/cancel';

        $violations = $this->createValidator()->validate($input);
        $this->assertCount(0, $violations);
    }

    public function testAcceptsHttpsUrls(): void
    {
        $input = new StripeCheckoutInput();
        $input->tier = 'PRO';
        $input->professionalProfileId = 1;
        $input->successUrl = 'https://app.quira.com/success';
        $input->cancelUrl = 'https://app.quira.com/cancel';

        $violations = $this->createValidator()->validate($input);
        $this->assertCount(0, $violations);
    }

    public function testRejectsInvalidTier(): void
    {
        $input = new StripeCheckoutInput();
        $input->tier = 'INVALID';
        $input->professionalProfileId = 1;
        $input->successUrl = 'https://example.com/success';
        $input->cancelUrl = 'https://example.com/cancel';

        $violations = $this->createValidator()->validate($input);
        $this->assertGreaterThan(0, $violations->count());
        $this->assertStringContainsString('tier', strtolower($violations->get(0)->getPropertyPath()));
    }

    public function testRejectsInvalidUrl(): void
    {
        $input = new StripeCheckoutInput();
        $input->tier = 'SOLVER';
        $input->professionalProfileId = 1;
        $input->successUrl = 'not-a-url';
        $input->cancelUrl = 'https://example.com/cancel';

        $violations = $this->createValidator()->validate($input);
        $this->assertGreaterThan(0, $violations->count());
    }

    public function testRejectsNegativeProfessionalProfileId(): void
    {
        $input = new StripeCheckoutInput();
        $input->tier = 'SOLVER';
        $input->professionalProfileId = 0;
        $input->successUrl = 'https://example.com/success';
        $input->cancelUrl = 'https://example.com/cancel';

        $violations = $this->createValidator()->validate($input);
        $this->assertGreaterThan(0, $violations->count());
    }
}
