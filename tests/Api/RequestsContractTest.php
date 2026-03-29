<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\VisitRequest as VisitRequestEntity;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class RequestsContractTest extends ApiTestCase
{
    public function testAssignedProfessionalAndVisitVisibilityControlsPhonesAndPreciseAddress(): void
    {
        $client = $this->createClientUser(
            email: 'client-contract@test.com',
            phoneNumber: '+34600000000',
            verifiedPhone: true,
            avatar: 'https://example.com/client-avatar.jpg',
            rating: 4.2,
            reviewCount: 3,
            notifyRequestActivity: false, // avoid external calls when we create VisitRequest directly
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $assignedPro = $this->createProfessionalUser(
            email: 'pro-assigned@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600111111',
            verifiedPhone: true,
            avatar: 'https://example.com/pro-avatar.jpg',
            rating: 4.7,
            reviewCount: 10,
            notifyRequestActivity: false,
        );
        $assignedProProfile = $assignedPro->getProfessionalProfile();
        $this->assertNotNull($assignedProProfile);

        $otherPro = $this->createProfessionalUser(
            email: 'pro-other@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600222222',
            verifiedPhone: true,
            avatar: 'https://example.com/pro-other-avatar.jpg',
            rating: 3.9,
            reviewCount: 2,
            notifyRequestActivity: false,
        );
        $otherProProfile = $otherPro->getProfessionalProfile();
        $this->assertNotNull($otherProProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::ACCEPTED,
            riskLevel: RiskLevel::HIGH,
            title: 'Test request for contract',
            preciseAddress: 'Avenida de Libia - 53',
            desiredExecutionTime: 'Lo antes posible'
        );
        $request->setAssignedProfessional($assignedProProfile);
        $this->em->persist($request);
        $this->em->flush();

        // As assigned professional: client phone and preciseAddress should be visible
        $this->browser->request('GET', '/api/requests/' . $request->getId(), [], [], $this->authHeaders($assignedPro));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());

        $this->assertArrayHasKey('client', $data);
        $this->assertIsArray($data['client']);
        $this->assertArrayHasKey('phoneNumber', $data['client']);
        $this->assertSame('+34600000000', $data['client']['phoneNumber']);
        $this->assertSame('https://example.com/client-avatar.jpg', $data['client']['avatar']);
        $this->assertSame(4.2, $data['client']['rating']);
        $this->assertSame(3, $data['client']['reviewCount']);

        $this->assertArrayHasKey('preciseAddress', $data);
        $this->assertSame('Avenida de Libia - 53', $data['preciseAddress']);

        // As other professional (without a bid and without an accepted visit): should not see request details (CurrentUserExtension filters it out).
        $this->browser->request('GET', '/api/requests/' . $request->getId(), [], [], $this->authHeaders($otherPro));
        $this->assertResponseStatusCodeSame(404);

        // Create accepted visit for other professional directly (disable notifications by notifyRequestActivity=false)
        $this->createVisitRequest(
            request: $request,
            professionalProfile: $otherProProfile,
            status: VisitRequestEntity::STATUS_ACCEPTED,
        );

        $this->browser->request('GET', '/api/requests/' . $request->getId(), [], [], $this->authHeaders($otherPro));
        $this->assertResponseStatusCodeSame(200);

        $data3 = $this->decodeJsonResponse($this->browser->getResponse()->getContent());

        $this->assertArrayHasKey('client', $data3);
        $this->assertIsArray($data3['client']);
        $this->assertArrayHasKey('phoneNumber', $data3['client']);
        $this->assertSame('+34600000000', $data3['client']['phoneNumber']);

        $this->assertArrayHasKey('preciseAddress', $data3);
        $this->assertSame('Avenida de Libia - 53', $data3['preciseAddress']);
    }

    public function testGetRequestExposesClientOriginalDescription(): void
    {
        $client = $this->createClientUser(
            email: 'client-original-desc@test.com',
            phoneNumber: '+34600000003',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Arreglo urgente de prueba',
        );
        $request->setClientOriginalDescription('Lo que escribió el cliente antes de la IA');
        $this->em->flush();

        $this->browser->request('GET', '/api/requests/'.$request->getId(), [], [], $this->authHeaders($client));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('clientOriginalDescription', $data);
        $this->assertSame('Lo que escribió el cliente antes de la IA', $data['clientOriginalDescription']);
    }
}

