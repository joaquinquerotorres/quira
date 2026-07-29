<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CalibratePricingCommand;
use App\Entity\Bid;
use App\Entity\PricingRate;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Repository\PricingRateRepository;
use App\Service\GeminiCacheService;
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

        $subCategory = 'TEST_SUBCATEGORY_CALIBRATE_' . bin2hex(random_bytes(4));

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

        /** @var PricingRateRepository $repo */
        $repo = static::getContainer()->get(PricingRateRepository::class);
        /** @var GeminiCacheService $cache */
        $cache = static::getContainer()->get(GeminiCacheService::class);

        $command = static::getContainer()->get(CalibratePricingCommand::class);
        $tester = new CommandTester($command);
        $exit = $tester->execute([
            '--since' => '2000-01-01',
        ]);

        $this->assertSame(0, $exit);

        $rate = $repo->findOneBy(['subcategory' => $subCategory, 'zone' => 'Córdoba']);
        $this->assertInstanceOf(PricingRate::class, $rate);
        $this->assertSame('Alta', $rate->getComplexity());
        $this->assertSame('Córdoba', $rate->getZone());
        $this->assertGreaterThan(0, $rate->getPriceMin());
        $this->assertGreaterThan($rate->getPriceMin(), $rate->getPriceMax());

        // invalidateAll is invoked; calling again should be safe
        $this->assertGreaterThanOrEqual(0, $cache->invalidateAll());
    }
}
