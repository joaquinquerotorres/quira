<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\StripeCheckoutController;
use App\Dto\StripeCheckoutInput;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class StripeCheckoutControllerTest extends TestCase
{
    public function testReturnsCheckoutUrl(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Test');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('getOrCreateCustomer')->with($user)->willReturn('cus_xxx');
        $stripeService->method('createCheckoutSession')
            ->with($profile, 'SOLVER', 'http://localhost/success', 'http://localhost/cancel', 'cus_xxx')
            ->willReturn('https://checkout.stripe.com/c/pay/cs_xxx');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $controller = $this->createControllerWithUser($user, $stripeService, $profileRepo, $em);
        $input = $this->createInput('SOLVER', 1, 'http://localhost/success', 'http://localhost/cancel');

        $response = $controller->__invoke($input);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_xxx', $data['url']);
    }

    public function testThrowsWhenProfileNotFound(): void
    {
        $user = new User();
        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(999)->willReturn(null);

        $controller = $this->createControllerWithUser(
            $user,
            $this->createMock(StripeService::class),
            $profileRepo,
            $this->createMock(EntityManagerInterface::class)
        );
        $input = $this->createInput('SOLVER', 999, 'http://localhost/success', 'http://localhost/cancel');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Perfil profesional no encontrado');
        $controller->__invoke($input);
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

        $controller = $this->createControllerWithUser(
            $currentUser,
            $this->createMock(StripeService::class),
            $profileRepo,
            $this->createMock(EntityManagerInterface::class)
        );
        $input = $this->createInput('SOLVER', 1, 'http://localhost/success', 'http://localhost/cancel');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Solo puedes crear sesiones de pago para tu propio perfil');
        $controller->__invoke($input);
    }

    public function testSavesStripeCustomerIdWhenFirstTime(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');

        $profile = new ProfessionalProfile();
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        $profileRepo = $this->createMock(ProfessionalProfileRepository::class);
        $profileRepo->method('find')->with(1)->willReturn($profile);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('getOrCreateCustomer')->willReturn('cus_new123');
        $stripeService->method('createCheckoutSession')->willReturn('https://checkout.stripe.com/xxx');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $controller = $this->createControllerWithUser($user, $stripeService, $profileRepo, $em);
        $input = $this->createInput('PRO', 1, 'https://app.com/success', 'https://app.com/cancel');

        $controller->__invoke($input);

        $this->assertSame('cus_new123', $user->getStripeCustomerId());
    }

    private function createControllerWithUser(
        User $user,
        StripeService $stripeService,
        ProfessionalProfileRepository $profileRepo,
        EntityManagerInterface $em
    ): TestableStripeCheckoutController {
        return new TestableStripeCheckoutController($user, $stripeService, $profileRepo, $em);
    }

    private function createInput(string $tier, int $profileId, string $successUrl, string $cancelUrl): StripeCheckoutInput
    {
        $input = new StripeCheckoutInput();
        $input->tier = $tier;
        $input->professionalProfileId = $profileId;
        $input->successUrl = $successUrl;
        $input->cancelUrl = $cancelUrl;
        return $input;
    }
}

/**
 * Subclass that injects the authenticated user for testing.
 */
final class TestableStripeCheckoutController extends StripeCheckoutController
{
    public function __construct(
        private readonly User $testUser,
        \App\Service\StripeService $stripeService,
        \App\Repository\ProfessionalProfileRepository $professionalProfileRepository,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {
        parent::__construct($stripeService, $professionalProfileRepository, $entityManager);
    }

    public function getUser(): ?User
    {
        return $this->testUser;
    }
}
