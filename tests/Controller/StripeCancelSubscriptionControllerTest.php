<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StripeCancelSubscriptionController;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class StripeCancelSubscriptionControllerTest extends TestCase
{
    public function testCancelsSubscriptionWithoutChangingRolesOrPaidThroughAt(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $user->setRoles(['ROLE_USER', 'ROLE_PRO']);
        $user->setStripeCustomerId('cus_123');

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Test');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);
        $profile->setPaidThroughAt(new \DateTimeImmutable());

        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService
            ->expects($this->once())
            ->method('cancelActiveSubscriptionsForCustomer')
            ->with('cus_123');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $controller = new TestableStripeCancelSubscriptionController($user, $stripeService, $profileRepo, $em);

        $request = Request::create(
            '/api/stripe/cancel-subscription',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['professionalProfileId' => 1], JSON_THROW_ON_ERROR)
        );

        $response = $controller->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        // Roles y paidThroughAt no cambian; solo se marca cancel at period end.
        $this->assertSame(['ROLE_USER', 'ROLE_PRO'], $user->getRoles());
        $this->assertNotNull($profile->getPaidThroughAt());
        $this->assertTrue($profile->isSubscriptionCancelAtPeriodEnd());
    }

    public function testThrowsWhenProfileNotFound(): void
    {
        $user = new User();

        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(999)->willReturn(null);

        $controller = new TestableStripeCancelSubscriptionController(
            $user,
            $this->createMock(StripeService::class),
            $profileRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        $request = Request::create(
            '/api/stripe/cancel-subscription',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['professionalProfileId' => 999], JSON_THROW_ON_ERROR)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Perfil profesional no encontrado');
        $controller->__invoke($request);
    }

    public function testThrowsWhenProfileBelongsToAnotherUser(): void
    {
        $currentUser = new User();
        $currentUser->setEmail('current@test.com');

        $otherUser = new User();
        $otherUser->setEmail('other@test.com');

        $profile = new ProfessionalProfile();
        $profile->setUser($otherUser);

        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $controller = new TestableStripeCancelSubscriptionController(
            $currentUser,
            $this->createMock(StripeService::class),
            $profileRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        $request = Request::create(
            '/api/stripe/cancel-subscription',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['professionalProfileId' => 1], JSON_THROW_ON_ERROR)
        );

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Solo puedes cancelar la suscripción de tu propio perfil');
        $controller->__invoke($request);
    }
}

final class TestableStripeCancelSubscriptionController extends StripeCancelSubscriptionController
{
    public function __construct(
        private readonly User $testUser,
        StripeService $stripeService,
        ProfessionalProfileRepository $professionalProfileRepository,
        EntityManagerInterface $entityManager,
    ) {
        parent::__construct($stripeService, $professionalProfileRepository, $entityManager);
    }

    public function getUser(): ?User
    {
        return $this->testUser;
    }
}

