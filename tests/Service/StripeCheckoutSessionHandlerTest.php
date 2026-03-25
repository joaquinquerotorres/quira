<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Service\StripeCheckoutSessionHandler;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Subscription;

final class StripeCheckoutSessionHandlerTest extends TestCase
{
    public function testUpdatesProfileAndUserRolesForPro(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $user->setRoles(['ROLE_USER', 'ROLE_FREE']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Test');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        $session = $this->createSession(1, 'PRO', 1743638399);
        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('getSessionWithSubscription')
            ->willReturn($this->createStripeSessionWithSubscription(1743638399));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $handler = new StripeCheckoutSessionHandler($stripeService, $profileRepo, $em);
        $handler->handleCompletedSession($session);

        $this->assertNotNull($profile->getPaidThroughAt());
        $this->assertSame(1743638399, $profile->getPaidThroughAt()->getTimestamp());
        $this->assertFalse($profile->isSubscriptionCancelAtPeriodEnd());
        $roles = $user->getRoles();
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertNotContains('ROLE_FREE', $roles);
    }

    public function testUpdatesProfileAndUserRolesForSolver(): void
    {
        $user = new User();
        $user->setEmail('solver@test.com');
        $user->setRoles(['ROLE_USER', 'ROLE_FREE']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Solver Test');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        $session = $this->createSession(1, 'SOLVER', 1743638399);
        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('getSessionWithSubscription')
            ->willReturn($this->createStripeSessionWithSubscription(1743638399));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $handler = new StripeCheckoutSessionHandler($stripeService, $profileRepo, $em);
        $handler->handleCompletedSession($session);

        $this->assertFalse($profile->isSubscriptionCancelAtPeriodEnd());
        $roles = $user->getRoles();
        $this->assertContains('ROLE_SOLVER', $roles);
        $this->assertNotContains('ROLE_FREE', $roles);
    }

    public function testThrowsWhenProfileNotFound(): void
    {
        $session = $this->createSession(999, 'PRO', 1743638399);
        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(999)->willReturn(null);

        $handler = new StripeCheckoutSessionHandler(
            $this->createMock(StripeService::class),
            $profileRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Professional profile not found');
        $handler->handleCompletedSession($session);
    }

    public function testThrowsWhenMetadataMissing(): void
    {
        $session = new \stdClass();
        $session->id = 'cs_xxx';
        $session->subscription = 'sub_xxx';
        $session->metadata = new \stdClass();
        $session->metadata->professionalProfileId = null;
        $session->metadata->tier = null;

        $handler = new StripeCheckoutSessionHandler(
            $this->createMock(StripeService::class),
            $this->createMock(ProfessionalProfileRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing metadata');
        $handler->handleCompletedSession($session);
    }

    private function createSession(int $profileId, string $tier, int $periodEnd): object
    {
        $session = new \stdClass();
        $session->id = 'cs_test123';
        $session->subscription = 'sub_xxx';
        $session->metadata = new \stdClass();
        $session->metadata->professionalProfileId = (string) $profileId;
        $session->metadata->tier = $tier;
        return $session;
    }

    private function createStripeSessionWithSubscription(int $currentPeriodEnd): Session
    {
        $subscription = new Subscription('sub_xxx');
        $subscription->current_period_end = $currentPeriodEnd;

        $session = new Session('cs_test123');
        $session->subscription = $subscription;

        return $session;
    }
}
