<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class RequestAndBidChoiceValidationTest extends ApiTestCase
{
    public function testPatchRequestRejectsInvalidDesiredExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-choice@test.com',
            phoneNumber: '+34600000100',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Choice validation request',
        );

        $this->browser->request(
            'PATCH',
            '/api/requests/' . $request->getId(),
            [],
            [],
            array_merge($this->authHeaders($client), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            json_encode(['desiredExecutionTime' => 'INVALID'], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPostRequestAcceptsFechaConcretaDesiredExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-fecha-req@test.com',
            phoneNumber: '+34600000200',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $payload = [
            'title' => 'Pintar salón con fecha concreta',
            'description' => 'Necesito pintar el salón la fecha indicada.',
            'estimatedPriceMin' => 8000,
            'estimatedPriceMax' => 12000,
            'address' => 'Calle Test 1, Córdoba',
            'category' => 'PAINTING',
            'riskLevel' => 'LOW',
            'desiredExecutionTime' => 'Fecha concreta: 15/08/2026',
        ];

        $this->browser->request(
            'POST',
            '/api/requests',
            [],
            [],
            array_merge($this->authHeaders($client), ['CONTENT_TYPE' => 'application/json']),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame('Fecha concreta: 15/08/2026', $data['desiredExecutionTime'] ?? null);
    }

    public function testPatchRequestAcceptsPresetDesiredExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-preset-req@test.com',
            phoneNumber: '+34600000210',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Preset availability request',
        );

        $this->browser->request(
            'PATCH',
            '/api/requests/' . $request->getId(),
            [],
            [],
            array_merge($this->authHeaders($client), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            json_encode(['desiredExecutionTime' => 'Esta semana'], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(200);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame('Esta semana', $data['desiredExecutionTime'] ?? null);
    }

    public function testPostBidRejectsInvalidEstimatedExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-choice2@test.com',
            phoneNumber: '+34600000110',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $pro = $this->createProfessionalUser(
            email: 'pro-choice@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000111',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Choice validation request 2',
        );

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            array_merge($this->authHeaders($pro), ['CONTENT_TYPE' => 'application/json']),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'priceQuote' => 12345,
                'estimatedExecutionTime' => 'INVALID',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPostBidAcceptsValidEstimatedExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-choice3@test.com',
            phoneNumber: '+34600000120',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $pro = $this->createProfessionalUser(
            email: 'pro-choice2@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000121',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Choice validation request 3',
        );

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            array_merge($this->authHeaders($pro), ['CONTENT_TYPE' => 'application/json']),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'priceQuote' => 9999,
                'estimatedExecutionTime' => 'Esta semana',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(201);

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertArrayHasKey('estimatedExecutionTime', $data);
        $this->assertSame('Esta semana', $data['estimatedExecutionTime']);
    }

    public function testPostBidAcceptsFechaConcretaEstimatedExecutionTime(): void
    {
        $client = $this->createClientUser(
            email: 'client-fecha-bid@test.com',
            phoneNumber: '+34600000220',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $pro = $this->createProfessionalUser(
            email: 'pro-fecha-bid@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000221',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Fecha concreta bid request',
        );

        $this->browser->request(
            'POST',
            '/api/bids',
            [],
            [],
            array_merge($this->authHeaders($pro), ['CONTENT_TYPE' => 'application/json']),
            json_encode([
                'request' => '/api/requests/' . $request->getId(),
                'priceQuote' => 11000,
                'estimatedExecutionTime' => 'Fecha concreta: 20/09/2026',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertSame('Fecha concreta: 20/09/2026', $data['estimatedExecutionTime'] ?? null);
    }
}
