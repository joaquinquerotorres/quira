<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class CanBidTest extends ApiTestCase
{
    public function testCanBidFalseWhenReachedMonthlyLimitWithPendingBids(): void
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-canbid-1@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_FREE'],
            phoneNumber: null,
            verifiedPhone: false,
            avatar: 'https://example.com/pro-canbid-1.jpg',
            rating: 4.0,
            reviewCount: 0,
            notifyRequestActivity: false,
        );

        $client = $this->createClientUser(
            email: 'client-canbid-1@test.com',
            phoneNumber: null,
            verifiedPhone: false,
            avatar: 'https://example.com/client-canbid-1.jpg',
            rating: null,
            reviewCount: 0,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();

        $limit = 3; // should match free pro monthly limit
        for ($i = 0; $i < $limit; $i++) {
            $request = $this->createRequest(
                clientProfile: $clientProfile,
                status: RequestStatus::PENDING,
                riskLevel: RiskLevel::LOW,
            );

            $this->createBid(
                request: $request,
                professionalUser: $pro,
                status: BidStatus::PENDING,
                priceQuote: 1000 + ($i * 100),
            );
        }

        $this->browser->request('GET', '/api/professionals/me/can-bid', [], [], $this->authHeaders($pro));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('canBidThisMonth', $data);
        $this->assertFalse($data['canBidThisMonth']);
    }

    public function testCanBidTrueWhenOnlyWithdrawnBidsAreExcluded(): void
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-canbid-2@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_FREE'],
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );

        $client = $this->createClientUser(
            email: 'client-canbid-2@test.com',
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();

        // 2 pending bids on PENDING requests + 1 withdrawn bid (REJECTED on PENDING request).
        for ($i = 0; $i < 2; $i++) {
            $request = $this->createRequest(
                clientProfile: $clientProfile,
                status: RequestStatus::PENDING,
                riskLevel: RiskLevel::LOW,
            );

            $this->createBid(
                request: $request,
                professionalUser: $pro,
                status: BidStatus::PENDING,
                priceQuote: 1100 + ($i * 100),
            );
        }

        $withdrawnRequest = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
        );

        $this->createBid(
            request: $withdrawnRequest,
            professionalUser: $pro,
            status: BidStatus::REJECTED,
            priceQuote: 1200,
        );

        $this->browser->request('GET', '/api/professionals/me/can-bid', [], [], $this->authHeaders($pro));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('canBidThisMonth', $data);
        $this->assertTrue($data['canBidThisMonth']);
    }

    public function testCanBidFalseWhenRejectedBidOnAcceptedRequestIsCounted(): void
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-canbid-3@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_FREE'],
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );

        $client = $this->createClientUser(
            email: 'client-canbid-3@test.com',
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();

        // 2 pending bids on PENDING requests + 1 rejected bid on ACCEPTED request.
        for ($i = 0; $i < 2; $i++) {
            $request = $this->createRequest(
                clientProfile: $clientProfile,
                status: RequestStatus::PENDING,
                riskLevel: RiskLevel::LOW,
            );

            $this->createBid(
                request: $request,
                professionalUser: $pro,
                status: BidStatus::PENDING,
                priceQuote: 1300 + ($i * 100),
            );
        }

        $acceptedRequest = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::ACCEPTED,
            riskLevel: RiskLevel::LOW,
        );

        $this->createBid(
            request: $acceptedRequest,
            professionalUser: $pro,
            status: BidStatus::REJECTED,
            priceQuote: 1400,
        );

        $this->browser->request('GET', '/api/professionals/me/can-bid', [], [], $this->authHeaders($pro));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('canBidThisMonth', $data);
        $this->assertFalse($data['canBidThisMonth']);
    }

    public function testCanBidFalseWhenRoleProButPaidThroughExpired(): void
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-canbid-expired@test.com',
            roles: ['ROLE_PROFESSIONAL', 'ROLE_PRO'],
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );
        $pro->getProfessionalProfile()?->setPaidThroughAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $client = $this->createClientUser(
            email: 'client-canbid-expired@test.com',
            phoneNumber: null,
            verifiedPhone: false,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();

        for ($i = 0; $i < 3; $i++) {
            $request = $this->createRequest(
                clientProfile: $clientProfile,
                status: RequestStatus::PENDING,
                riskLevel: RiskLevel::LOW,
            );

            $this->createBid(
                request: $request,
                professionalUser: $pro,
                status: BidStatus::PENDING,
                priceQuote: 2000 + ($i * 100),
            );
        }

        $this->browser->request('GET', '/api/professionals/me/can-bid', [], [], $this->authHeaders($pro));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertFalse($data['canBidThisMonth']);
    }
}

