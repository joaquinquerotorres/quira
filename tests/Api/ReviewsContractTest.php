<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Review;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class ReviewsContractTest extends ApiTestCase
{
    public function testListByTargetReturnsReceivedReviewsWithVirtualFields(): void
    {
        $client = $this->createClientUser('client-reviews@test.com');
        $pro = $this->createProfessionalUser('pro-reviews@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::COMPLETED,
            RiskLevel::LOW,
            'Reparación cocina',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $review = new Review();
        $review->setRequest($request);
        $review->setAuthor($pro);
        $review->setTarget($client);
        $review->setScore(5);
        $review->setComment('Cliente excelente');
        $this->em->persist($review);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/reviews?target=/api/users/'.$client->getId(),
            server: $this->authHeaders($client)
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(1, $members);
        $item = $members[0];
        self::assertSame(5, $item['score'] ?? $item['rating'] ?? null);
        self::assertSame('Cliente excelente', $item['comment'] ?? $item['text'] ?? null);
        self::assertSame('Pro pro-reviews@test.com', $item['author'] ?? null);
        self::assertSame('Client client-reviews@test.com', $item['targetName'] ?? null);
        self::assertSame('Reparación cocina', $item['requestTitle'] ?? null);
        self::assertTrue($item['authorIsProfessional'] ?? false);
    }

    public function testListByAuthorReturnsWrittenReviews(): void
    {
        $client = $this->createClientUser('client-author@test.com');
        $pro = $this->createProfessionalUser('pro-author@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::COMPLETED,
            RiskLevel::LOW,
            'Montaje mueble',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $review = new Review();
        $review->setRequest($request);
        $review->setAuthor($client);
        $review->setTarget($pro);
        $review->setScore(4);
        $review->setComment('Buen trabajo');
        $this->em->persist($review);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/reviews?author=/api/users/'.$client->getId(),
            server: $this->authHeaders($client)
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(1, $members);
        $item = $members[0];
        self::assertSame('Pro pro-author@test.com', $item['targetName'] ?? null);
        self::assertSame('Montaje mueble', $item['requestTitle'] ?? null);
        self::assertFalse($item['authorIsProfessional'] ?? true);
    }

    public function testCannotListOtherUsersReviews(): void
    {
        $clientA = $this->createClientUser('client-a@test.com');
        $clientB = $this->createClientUser('client-b@test.com');
        $pro = $this->createProfessionalUser('pro-hidden@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $clientA->getClientProfile(),
            RequestStatus::COMPLETED,
            RiskLevel::LOW,
            'Privado',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $review = new Review();
        $review->setRequest($request);
        $review->setAuthor($pro);
        $review->setTarget($clientA);
        $review->setScore(5);
        $this->em->persist($review);
        $this->em->flush();

        // B intenta listar recibidas de A
        $this->browser->request(
            'GET',
            '/api/reviews?target=/api/users/'.$clientA->getId(),
            server: $this->authHeaders($clientB)
        );
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(0, $members);

        // B intenta listar escritas por el pro (ajeno)
        $this->browser->request(
            'GET',
            '/api/reviews?author=/api/users/'.$pro->getId(),
            server: $this->authHeaders($clientB)
        );
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(0, $members);
    }

    public function testRequestAndAuthorFilterStillWorksForSelfCheck(): void
    {
        $client = $this->createClientUser('client-selfcheck@test.com');
        $pro = $this->createProfessionalUser('pro-selfcheck@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::COMPLETED,
            RiskLevel::LOW,
            'Self check',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $review = new Review();
        $review->setRequest($request);
        $review->setAuthor($client);
        $review->setTarget($pro);
        $review->setScore(3);
        $this->em->persist($review);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/reviews?request=/api/requests/'.$request->getId().'&author=/api/users/'.$client->getId(),
            server: $this->authHeaders($client)
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(1, $members);
        self::assertSame($review->getId(), $members[0]['id'] ?? null);
    }
}
