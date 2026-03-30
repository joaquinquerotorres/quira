<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Request;
use App\Enum\Category;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestClientOriginalDescriptionValidationTest extends KernelTestCase
{
    public function testOnlyClientOriginalDescriptionPassesDescriptionOrMediaCallback(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $r = $this->minimalValidRequest();
        $r->setDescription(null);
        $r->setClientOriginalDescription('Texto original del cliente sin description');

        $violations = $validator->validate($r);
        foreach ($violations as $v) {
            self::assertNotSame('description', $v->getPropertyPath(), $v->getMessage());
        }
    }

    public function testClientOriginalDescriptionExceeds5000Chars(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get('validator');

        $r = $this->minimalValidRequest();
        $r->setClientOriginalDescription(str_repeat('a', 5001));

        $violations = $validator->validate($r);
        $paths = array_map(fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));
        self::assertContains('clientOriginalDescription', $paths);
    }

    private function minimalValidRequest(): Request
    {
        $r = new Request();
        $r->setTitle('12345678901234567890');
        $r->setAddress('Calle Test 1');
        $r->setEstimatedPriceMin(100);
        $r->setEstimatedPriceMax(100);
        $r->setCategory(Category::DIY);
        $r->setRiskLevel(RiskLevel::LOW);
        $r->setStatus(RequestStatus::PENDING);

        return $r;
    }
}
