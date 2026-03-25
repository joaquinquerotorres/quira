<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CalibratePricingCommand;
use App\Entity\Bid;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Tests\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('database')]
final class CalibratePricingCommandTest extends ApiTestCase
{
    public function testCommandAddsNewSubcategoryWithCordobaAndComplexityFromRisk(): void
    {
        $client = $this->createClientUser(
            email: 'client-calibrate@test.com',
            phoneNumber: '+34600000350',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $pro = $this->createProfessionalUser(
            email: 'pro-calibrate@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000351',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $subCategory = 'TEST_SUBCATEGORY_CALIBRATE';

        // Need >=3 jobs for factor generation.
        for ($i = 0; $i < 3; $i++) {
            $req = $this->createRequest(
                clientProfile: $clientProfile,
                status: RequestStatus::ACCEPTED,
                riskLevel: RiskLevel::HIGH,
                title: 'Calibrate #' . $i,
            );
            $req->setAiDiagnosis([
                'estimated_price_min' => 10000,
                'estimated_price_max' => 20000,
                'sub_category' => $subCategory,
                'risk_level' => 'HIGH',
            ]);
            $this->em->persist($req);

            $bid = new Bid();
            $bid->setRequest($req);
            $bid->setProfessional($pro);
            $bid->setPriceQuote(18000);
            $bid->setStatus(BidStatus::ACCEPTED);
            $req->addBid($bid);
            $this->em->persist($bid);
        }
        $this->em->flush();

        $csvPath = \dirname(__DIR__, 2) . '/config/gemini_pricing.csv';
        $original = file_get_contents($csvPath);
        $this->assertIsString($original);

        try {
            $command = static::getContainer()->get(CalibratePricingCommand::class);
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                '--since' => '2000-01-01',
            ]);

            $this->assertSame(0, $exit);

            $updated = file_get_contents($csvPath);
            $this->assertIsString($updated);
            $this->assertStringContainsString(',' . $subCategory . ',Córdoba,', $updated);
            // Complejidad derivada de HIGH => Alta (última columna)
            $this->assertStringContainsString(',' . $subCategory . ',Córdoba,', $updated);
            $this->assertStringContainsString(',Alta', $updated);
        } finally {
            file_put_contents($csvPath, $original);
        }
    }
}

