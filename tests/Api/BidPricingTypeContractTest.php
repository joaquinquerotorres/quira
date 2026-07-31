<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Request;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class BidPricingTypeContractTest extends ApiTestCase
{
    public function testRangeBidOnFixedRequestReturns201(): void
    {
        [$pro, $request] = $this->seedPendingRequest(Request::PRICING_TYPE_FIXED);

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($pro),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'RANGE',
                'priceQuote' => 10000,
                'priceQuoteMin' => 10000,
                'priceQuoteMax' => 15000,
                'comment' => 'Depende de materiales',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame('RANGE', $data['pricingType'] ?? null);
        $this->assertSame('Depende de materiales', $data['comment'] ?? null);
    }

    public function testFixedBidOnRangeRequestReturns201(): void
    {
        [$pro, $request] = $this->seedPendingRequest(Request::PRICING_TYPE_RANGE);

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($pro),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'FIXED',
                'priceQuote' => 12000,
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame('FIXED', $data['pricingType'] ?? null);
    }

    public function testRangeBidWithoutCommentReturns422(): void
    {
        [$pro, $request] = $this->seedPendingRequest(Request::PRICING_TYPE_FIXED);

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($pro),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'RANGE',
                'priceQuote' => 10000,
                'priceQuoteMin' => 10000,
                'priceQuoteMax' => 15000,
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(422);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $violations = $data['violations'] ?? [];
        $this->assertNotEmpty($violations);
        $codes = array_column($violations, 'code');
        $this->assertContains('BID_RANGE_COMMENT_REQUIRED', $codes);
    }

    public function testFixedBidWithoutCommentReturns201(): void
    {
        [$pro, $request] = $this->seedPendingRequest(Request::PRICING_TYPE_RANGE);

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($pro),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'FIXED',
                'priceQuote' => 9000,
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);
    }

    public function testVisitRequiredStillAcceptsFixedAndRangeBids(): void
    {
        [$proFixed, $request] = $this->seedPendingRequest(Request::PRICING_TYPE_VISIT_REQUIRED);

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($proFixed),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'FIXED',
                'priceQuote' => 5000,
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(201);

        $proRange = $this->createProfessionalUser(
            email: 'pro-bid-range-visit@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_PRO'],
            phoneNumber: '+34600111222',
            verifiedPhone: true,
        );
        $proRange->getProfessionalProfile()?->setPaidThroughAt(new \DateTimeImmutable('+30 days'));
        $this->em->flush();

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            $this->authHeaders($proRange),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'pricingType' => 'RANGE',
                'priceQuote' => 4000,
                'priceQuoteMin' => 4000,
                'priceQuoteMax' => 8000,
                'comment' => 'Orientativo tras visita',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{0: \App\Entity\User, 1: Request}
     */
    private function seedPendingRequest(string $requestPricingType): array
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-bid-pricing-' . uniqid('', true) . '@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_PRO'],
            phoneNumber: '+34600999888',
            verifiedPhone: true,
        );
        $pro->getProfessionalProfile()?->setPaidThroughAt(new \DateTimeImmutable('+30 days'));
        $this->em->flush();

        $client = $this->createClientUser(
            email: 'client-bid-pricing-' . uniqid('', true) . '@test.com',
            phoneNumber: '+34600888777',
            verifiedPhone: true,
        );
        $clientProfile = $client->getClientProfile();
        self::assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
        );
        $request->setPricingType($requestPricingType);
        $this->em->flush();

        return [$pro, $request];
    }
}
