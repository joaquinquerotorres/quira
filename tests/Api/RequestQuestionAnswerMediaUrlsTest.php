<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\RequestQuestion;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class RequestQuestionAnswerMediaUrlsTest extends ApiTestCase
{
    public function testPatchRejectsMoreThanThreeAnswerMediaUrls(): void
    {
        $client = $this->createClientUser(
            email: 'client-q@test.com',
            phoneNumber: '+34600000001',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Request for question',
            preciseAddress: null,
            desiredExecutionTime: 'Lo antes posible',
        );

        $pro = $this->createProfessionalUser(
            email: 'pro-q@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000002',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $q = new RequestQuestion();
        $q->setRequest($request);
        $q->setAuthor($pro);
        $q->setQuestionText('¿Puedes enviar más detalles?');
        $this->em->persist($q);
        $this->em->flush();

        $payload = [
            'answerText' => 'Ok',
            'answerMediaUrls' => [
                'https://example.com/1.jpg',
                'https://example.com/2.jpg',
                'https://example.com/3.mp4',
                'https://example.com/4.jpg',
            ],
        ];

        $this->browser->request(
            'PATCH',
            '/api/request_questions/' . $q->getId(),
            [],
            [],
            array_merge($this->authHeaders($client), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchAcceptsUpToThreeAnswerMediaUrls(): void
    {
        $client = $this->createClientUser(
            email: 'client-q2@test.com',
            phoneNumber: '+34600000011',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $clientProfile = $client->getClientProfile();
        $this->assertNotNull($clientProfile);

        $request = $this->createRequest(
            clientProfile: $clientProfile,
            status: RequestStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            title: 'Request for question 2',
        );

        $pro = $this->createProfessionalUser(
            email: 'pro-q2@test.com',
            roles: ['ROLE_USER', 'ROLE_PROFESSIONAL'],
            phoneNumber: '+34600000012',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );

        $q = new RequestQuestion();
        $q->setRequest($request);
        $q->setAuthor($pro);
        $q->setQuestionText('¿Tienes fotos?');
        $this->em->persist($q);
        $this->em->flush();

        $payload = [
            'answerText' => 'Sí',
            'answerMediaUrls' => [
                'https://example.com/1.jpg',
                'https://example.com/2.jpg',
                'https://example.com/3.mp4',
            ],
        ];

        $this->browser->request(
            'PATCH',
            '/api/request_questions/' . $q->getId(),
            [],
            [],
            array_merge($this->authHeaders($client), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        $this->em->refresh($q);
        $this->assertSame('Sí', $q->getAnswerText());
        $this->assertSame($payload['answerMediaUrls'], $q->getAnswerMediaUrls());
    }
}

