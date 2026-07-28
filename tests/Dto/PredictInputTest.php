<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\PredictInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PredictInputTest extends TestCase
{
    public function testValidInputWithDescription(): void
    {
        $input = new PredictInput(description: 'Gotea el grifo');
        $this->assertSame('Gotea el grifo', $input->description);
        $this->assertNull($input->image);
        $this->assertTrue($input->hasContent());
        $this->assertFalse($input->hasUrlMedia());
    }

    public function testValidationAcceptsValidInput(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $input = new PredictInput(description: 'Test', location: 'Madrid');
        $violations = $validator->validate($input);
        $this->assertCount(0, $violations);
    }

    public function testAcceptsMediaUrls(): void
    {
        $input = new PredictInput(
            description: null,
            videoUrl: 'https://example.supabase.co/storage/v1/object/public/requests/1_video.mp4',
        );
        $this->assertTrue($input->hasUrlMedia());
        $this->assertTrue($input->hasContent());
        $this->assertFalse($input->hasLegacyBase64Media());
    }

    public function testHasContentWithOnlyImageUrl(): void
    {
        $input = new PredictInput(
            imageUrl: 'https://example.supabase.co/storage/v1/object/public/requests/1_photo.jpg',
        );
        $this->assertTrue($input->hasContent());
        $this->assertTrue($input->hasUrlMedia());
    }
}
