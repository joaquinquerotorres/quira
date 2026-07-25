<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\CalendarEvent;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Repository\CalendarEventRepository;
use App\State\CalendarEventOwnerProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CalendarEventOwnerProcessorTest extends TestCase
{
    private function buildProcessor(
        Security $security,
        CalendarEventRepository $repo,
        ?\ApiPlatform\State\ProcessorInterface $persist = null,
        ?\ApiPlatform\State\ProcessorInterface $remove = null,
    ): CalendarEventOwnerProcessor {
        $persist ??= $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persist->method('process')->willReturnCallback(fn ($data) => $data);
        $remove ??= $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        return new CalendarEventOwnerProcessor(
            $persist,
            $remove,
            $security,
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function makeAcceptedJob(): array
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proUser->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL']);
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Trabajo de prueba calendario');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(1000);
        $request->setEstimatedPriceMax(2000);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);
        $request->setStatus(RequestStatus::ACCEPTED);

        return [$proUser, $proProfile, $request];
    }

    public function testCreatesEventWhenAssignedAndAccepted(): void
    {
        [$proUser, $proProfile, $request] = $this->makeAcceptedJob();

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setStartsAt(new \DateTimeImmutable('2026-08-01 09:30:00'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $repo = $this->createMock(CalendarEventRepository::class);
        $repo->method('findOneByRequestAndProfessional')->willReturn(null);

        $processor = $this->buildProcessor($security, $repo);
        $result = $processor->process($event, new \ApiPlatform\Metadata\Post());

        $this->assertSame($proProfile, $result->getProfessional());
    }

    public function testThrowsConflictWhenDuplicate(): void
    {
        [$proUser, $proProfile, $request] = $this->makeAcceptedJob();

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setStartsAt(new \DateTimeImmutable('2026-08-01 09:30:00'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $repo = $this->createMock(CalendarEventRepository::class);
        $repo->method('findOneByRequestAndProfessional')->willReturn(new CalendarEvent());

        $processor = $this->buildProcessor($security, $repo);

        $this->expectException(ConflictHttpException::class);
        $processor->process($event, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenRequestNotAccepted(): void
    {
        [$proUser, $proProfile, $request] = $this->makeAcceptedJob();
        $request->setStatus(RequestStatus::PENDING);

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setStartsAt(new \DateTimeImmutable('2026-08-01 09:30:00'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $repo = $this->createMock(CalendarEventRepository::class);
        $repo->method('findOneByRequestAndProfessional')->willReturn(null);

        $processor = $this->buildProcessor($security, $repo);

        $this->expectException(BadRequestHttpException::class);
        $processor->process($event, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenNotAssignedProfessional(): void
    {
        [$proUser, , $request] = $this->makeAcceptedJob();

        $otherUser = new User();
        $otherUser->setEmail('other@test.com');
        $otherProfile = new ProfessionalProfile();
        $otherProfile->setFullName('Other');
        $otherProfile->setUser($otherUser);
        $otherUser->setProfessionalProfile($otherProfile);

        $event = new CalendarEvent();
        $event->setRequest($request);
        $event->setStartsAt(new \DateTimeImmutable('2026-08-01 09:30:00'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $repo = $this->createMock(CalendarEventRepository::class);
        $repo->method('findOneByRequestAndProfessional')->willReturn(null);

        $processor = $this->buildProcessor($security, $repo);

        $this->expectException(AccessDeniedHttpException::class);
        $processor->process($event, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenStartsAtMissing(): void
    {
        [$proUser, , $request] = $this->makeAcceptedJob();

        $event = new CalendarEvent();
        $event->setRequest($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $repo = $this->createMock(CalendarEventRepository::class);
        $repo->method('findOneByRequestAndProfessional')->willReturn(null);

        $processor = $this->buildProcessor($security, $repo);

        $this->expectException(BadRequestHttpException::class);
        $processor->process($event, new \ApiPlatform\Metadata\Post());
    }
}
