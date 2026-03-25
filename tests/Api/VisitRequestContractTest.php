<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\VisitRequest as VisitRequestEntity;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class VisitRequestContractTest extends ApiTestCase
{
    public function testVisitRequestLifecycleCreatesNotificationsAndRevealsPreciseAddressAfterAcceptance(): void
    {
        $client = $this->createClientUser(
            email: 'client-visit-contract@test.com',
            phoneNumber: null, // avoid WhatsApp network attempts
            verifiedPhone: true,
            avatar: 'https://example.com/client-visit.jpg',
            rating: 4.0,
            reviewCount: 1,
            notifyRequestActivity: true,
        );
        $clientProfile = $client->getClientProfile();

        $pro = $this->createProfessionalUser(
            email: 'pro-visit-contract@test.com',
            roles: ['ROLE_PRO', 'ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: null, // avoid WhatsApp network attempts
            verifiedPhone: true,
            avatar: 'https://example.com/pro-visit.jpg',
            rating: 4.6,
            reviewCount: 7,
            notifyRequestActivity: true,
        );
        $proProfile = $pro->getProfessionalProfile();
        $this->assertNotNull($proProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::HIGH,
            title: 'Request for visit contract',
            preciseAddress: 'Avenida de Libia - 53',
            desiredExecutionTime: 'Lo antes posible',
        );

        // Pro requests a visit
        $this->browser->request(
            'POST',
            '/api/requests/' . $request->getId() . '/visit-request',
            [],
            [],
            $this->authHeaders($pro),
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(201);

        $visitRequestResponse = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('id', $visitRequestResponse);
        $visitRequestId = (int) $visitRequestResponse['id'];

        $notifications = $this->getNotificationRepository()->findBy([
            'type' => 'VISIT_REQUEST_CREATED',
            'relatedId' => $request->getId(),
        ]);
        $this->assertCount(1, $notifications);

        // Client accepts visit
        $this->browser->request(
            'POST',
            '/api/visit-requests/' . $visitRequestId . '/accept',
            [],
            [],
            $this->authHeaders($client),
            json_encode([])
        );
        $this->assertResponseStatusCodeSame(200);

        $notifications2 = $this->getNotificationRepository()->findBy([
            'type' => 'VISIT_REQUEST_ACCEPTED',
            'relatedId' => $request->getId(),
        ]);
        $this->assertCount(1, $notifications2);

        // Pro should be allowed to see preciseAddress after acceptance
        $this->browser->request(
            'GET',
            '/api/requests/' . $request->getId(),
            [],
            [],
            $this->authHeaders($pro),
        );
        $this->assertResponseStatusCodeSame(200);

        $data3 = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('preciseAddress', $data3);
        $this->assertSame('Avenida de Libia - 53', $data3['preciseAddress']);

        // Sanity: Visit status is updated in response after accept
        $visitUpdated = $this->em->find(VisitRequestEntity::class, $visitRequestId);
        $this->assertSame(VisitRequestEntity::STATUS_ACCEPTED, $visitUpdated?->getStatus());
    }
}

