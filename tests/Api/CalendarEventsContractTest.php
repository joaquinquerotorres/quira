<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CalendarEvent;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class CalendarEventsContractTest extends ApiTestCase
{
    public function testCollectionEmbedsRequestIdAndStartsAt(): void
    {
        $client = $this->createClientUser('cal-client@test.com');
        $pro = $this->createProfessionalUser('cal-pro@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::ACCEPTED,
            RiskLevel::LOW,
            'Reparar persiana Córdoba',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $startsAt = new \DateTimeImmutable('2026-08-12T10:30:00+00:00');
        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setProfessional($pro->getProfessionalProfile());
        $event->setStartsAt($startsAt);
        $this->em->persist($event);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/calendar_events',
            server: $this->authHeaders($pro)
        );
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertCount(1, $members);
        $item = $members[0];

        self::assertSame($event->getId(), $item['id'] ?? null);
        self::assertNotEmpty($item['startsAt'] ?? null);
        self::assertStringContainsString('2026-08-12', (string) $item['startsAt']);

        $req = $item['request'] ?? null;
        self::assertIsArray($req, 'request debe venir embebido como objeto, no solo IRI');
        self::assertSame($request->getId(), $req['id'] ?? null);
        self::assertSame('Reparar persiana Córdoba', $req['title'] ?? null);
    }

    public function testPostUpsertsSameRequestProfessional(): void
    {
        $client = $this->createClientUser('cal-upsert-client@test.com');
        $pro = $this->createProfessionalUser('cal-upsert-pro@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::ACCEPTED,
            RiskLevel::LOW,
            'Montaje mueble calendario',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $payload1 = json_encode([
            'request' => '/api/requests/'.$request->getId(),
            'startsAt' => '2026-08-01T09:00:00',
        ], JSON_THROW_ON_ERROR);

        $this->browser->request(
            'POST',
            '/api/calendar_events',
            server: $this->authHeaders($pro),
            content: $payload1
        );
        self::assertResponseStatusCodeSame(201);
        $first = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $firstId = $first['id'] ?? null;
        self::assertNotNull($firstId);

        $payload2 = json_encode([
            'request' => '/api/requests/'.$request->getId(),
            'startsAt' => '2026-08-20T16:45:00',
        ], JSON_THROW_ON_ERROR);

        $this->browser->request(
            'POST',
            '/api/calendar_events',
            server: $this->authHeaders($pro),
            content: $payload2
        );
        self::assertResponseIsSuccessful();
        $second = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        self::assertSame($firstId, $second['id'] ?? null, 'POST debe actualizar el mismo evento (upsert)');
        self::assertStringContainsString('2026-08-20', (string) ($second['startsAt'] ?? ''));

        $count = (int) $this->em->getRepository(CalendarEvent::class)->count([
            'request' => $request,
            'professional' => $pro->getProfessionalProfile(),
        ]);
        self::assertSame(1, $count);

        $this->browser->request(
            'GET',
            '/api/calendar_events?request=/api/requests/'.$request->getId(),
            server: $this->authHeaders($pro)
        );
        self::assertResponseIsSuccessful();
        $filtered = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $filtered['hydra:member'] ?? $filtered['member'] ?? [];
        self::assertCount(1, $members);
        self::assertStringContainsString('2026-08-20', (string) ($members[0]['startsAt'] ?? ''));
    }

    public function testDateFilterStartsAtAfterBefore(): void
    {
        $client = $this->createClientUser('cal-filter-client@test.com');
        $pro = $this->createProfessionalUser('cal-filter-pro@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::ACCEPTED,
            RiskLevel::LOW,
            'Filtro fechas calendario xx',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setProfessional($pro->getProfessionalProfile());
        $event->setStartsAt(new \DateTimeImmutable('2026-08-15T12:00:00'));
        $this->em->persist($event);
        $this->em->flush();

        $this->browser->request(
            'GET',
            '/api/calendar_events?startsAt[after]=2026-08-01&startsAt[before]=2026-08-31T23:59:59',
            server: $this->authHeaders($pro)
        );
        self::assertResponseIsSuccessful();
        $inRange = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $inRange['hydra:member'] ?? $inRange['member'] ?? [];
        self::assertCount(1, $members);

        $this->browser->request(
            'GET',
            '/api/calendar_events?startsAt[after]=2026-09-01&startsAt[before]=2026-09-30T23:59:59',
            server: $this->authHeaders($pro)
        );
        self::assertResponseIsSuccessful();
        $out = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $membersOut = $out['hydra:member'] ?? $out['member'] ?? [];
        self::assertCount(0, $membersOut);
    }

    public function testPatchStartsAtThenListShowsSameValue(): void
    {
        $client = $this->createClientUser('cal-patch-client@test.com');
        $pro = $this->createProfessionalUser('cal-patch-pro@test.com', ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $request = $this->createRequest(
            $client->getClientProfile(),
            RequestStatus::ACCEPTED,
            RiskLevel::LOW,
            'Patch coherente startsAt xx',
        );
        $request->setAssignedProfessional($pro->getProfessionalProfile());
        $this->em->flush();

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setProfessional($pro->getProfessionalProfile());
        $event->setStartsAt(new \DateTimeImmutable('2026-08-01T09:00:00'));
        $this->em->persist($event);
        $this->em->flush();
        $eventId = $event->getId();

        $this->browser->request(
            'PATCH',
            '/api/calendar_events/'.$eventId,
            server: array_merge($this->authHeaders($pro), [
                'CONTENT_TYPE' => 'application/merge-patch+json',
            ]),
            content: json_encode(['startsAt' => '2026-08-25T14:15:00'], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();
        $patched = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('2026-08-25', (string) ($patched['startsAt'] ?? ''));

        $this->browser->request(
            'GET',
            '/api/calendar_events',
            server: $this->authHeaders($pro)
        );
        self::assertResponseIsSuccessful();
        $list = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        $members = $list['hydra:member'] ?? $list['member'] ?? [];
        self::assertCount(1, $members);
        self::assertSame($eventId, $members[0]['id'] ?? null);
        self::assertStringContainsString('2026-08-25', (string) ($members[0]['startsAt'] ?? ''));
    }
}
