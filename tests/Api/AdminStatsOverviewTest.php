<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class AdminStatsOverviewTest extends ApiTestCase
{
    public function testAnonymousGets401(): void
    {
        $this->browser->request('GET', '/api/admin/stats/overview?from=2026-07-01&to=2026-07-07');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testNonAdminGets403(): void
    {
        $user = $this->createClientUser(
            email: 'client-no-admin@test.com',
            phoneNumber: '+34600001001',
            verifiedPhone: true,
        );

        $this->browser->request(
            'GET',
            '/api/admin/stats/overview?from=2026-07-01&to=2026-07-07',
            [],
            [],
            $this->authHeaders($user)
        );
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminGets200WithContractShape(): void
    {
        $admin = $this->createAdminUser('admin-stats@test.com');

        $client = $this->createClientUser(
            email: 'client-for-admin-stats@test.com',
            phoneNumber: '+34600001002',
            verifiedPhone: true,
        );
        $clientProfile = $client->getClientProfile();
        self::assertNotNull($clientProfile);

        $pro = $this->createProfessionalUser(
            email: 'pro-for-admin-stats@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO'],
            phoneNumber: '+34600001003',
            verifiedPhone: true,
        );
        $pro->getProfessionalProfile()?->setPaidThroughAt(new \DateTimeImmutable('+10 days'));
        $this->em->flush();

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
        );
        $bid = $this->createBid($request, $pro, BidStatus::PENDING, 5000);
        $bid->setStatus(BidStatus::ACCEPTED);
        $request->setStatus(RequestStatus::ACCEPTED);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/admin/stats/overview?from=2026-07-01&to=2026-07-31',
            [],
            [],
            $this->authHeaders($admin)
        );
        $this->assertResponseStatusCodeSame(200);
        $contentType = (string) $this->browser->getResponse()->headers->get('content-type');
        $this->assertStringContainsString('application/json', $contentType);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame(['from', 'to', 'previousFrom', 'previousTo'], array_keys($data['period']));
        $this->assertArrayHasKey('newUsers', $data['kpis']);
        $this->assertArrayHasKey('value', $data['kpis']['newUsers']);
        $this->assertArrayHasKey('previous', $data['kpis']['newUsers']);
        $this->assertArrayHasKey('funnel', $data);
        $this->assertArrayHasKey('registered', $data['funnel']);
        $this->assertArrayHasKey('queues', $data);
        $this->assertArrayHasKey('pendingApproval', $data['queues']);
        $this->assertSame('day', $data['timeseries']['grain']);
        $this->assertCount(31, $data['timeseries']['points']);
        $this->assertSame(
            ['date', 'newUsers', 'newRequests', 'newBids', 'acceptedBids'],
            array_keys($data['timeseries']['points'][0])
        );
        // Sin PII
        $json = $this->browser->getResponse()->getContent();
        $this->assertStringNotContainsString('admin-stats@test.com', $json);
        $this->assertStringNotContainsString('+34600001002', $json);
    }

    public function testAdminRejectsMissingDates(): void
    {
        $admin = $this->createAdminUser('admin-missing-dates@test.com');
        $this->browser->request(
            'GET',
            '/api/admin/stats/overview',
            [],
            [],
            $this->authHeaders($admin)
        );
        $this->assertResponseStatusCodeSame(400);
    }

    private function createAdminUser(string $email): User
    {
        $user = $this->createClientUser(
            email: $email,
            phoneNumber: '+34600999000',
            verifiedPhone: true,
        );
        $user->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);
        $this->em->flush();

        return $user;
    }
}
