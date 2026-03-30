<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Repository\BidRepository;
use App\Service\ProfessionalSubscriptionService;
use App\State\BidProfessionalProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidProfessionalProcessorTest extends TestCase
{
    private LoggerInterface $logger;
    private \Closure $persistCallback;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->persistCallback = fn($data) => $data;
    }

    public function testSetsProfessionalAndStatusOnBid(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setPhoneNumber('+34600000000');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);
        $proProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);
        $bid->setComment('Puedo hacerlo');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(
            fn($data) => $data
        );

        $bidRepo = $this->createMock(BidRepository::class);
        $bidRepo->method('canProfessionalBidThisMonth')->willReturn(true);
        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $result = $processor->process($bid, $op);

        $this->assertSame($proUser, $result->getProfessional());
        $this->assertSame(\App\Enum\BidStatus::PENDING, $result->getStatus());
    }

    public function testThrowsWhenClientTriesToBidOnOwnRequest(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('No puedes hacer una oferta para tu propia solicitud');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenRequestNotPending(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setPhoneNumber('+34600000000');
        $proProfile->setVerifiedPhone(true);
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::ACCEPTED);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('La solicitud a la que pertenece esta oferta no está pendiente');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenUserHasNoProfessionalProfile(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        // No professional profile

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes completar tu perfil profesional');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenPhoneNotVerified(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setPhoneNumber('+34600000000');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);
        $proProfile->setVerifiedPhone(false);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes verificar tu número de teléfono antes de hacer una puja');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenPhoneNumberEmpty(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setPhoneNumber(null);
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);
        $proProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes añadir tu número de teléfono en tu perfil profesional');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenFreeProfessionalExceedsMonthlyBidLimit(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $freeProUser = new User();
        $freeProUser->setEmail('free@test.com');
        $freeProUser->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_FREE']);
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro Free');
        $proProfile->setPhoneNumber('+34600000000');
        $proProfile->setUser($freeProUser);
        $freeProUser->setProfessionalProfile($proProfile);
        $proProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($freeProUser);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);
        $bidRepo->method('canProfessionalBidThisMonth')->willReturn(false);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(ValidationException::class);
        $processor->process($bid, $op);
    }

    public function testThrowsWhenNoActiveSubscriptionOnHighRiskRequest(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proUser->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_SOLVER']);
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setPhoneNumber('+34600000000');
        $proProfile->setVerifiedPhone(true);
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test request title');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);
        $request->setRiskLevel(RiskLevel::HIGH);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setPriceQuote(80);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $bidRepo = $this->createMock(BidRepository::class);

        $processor = $this->createBidProcessor($persistProcessor, $security, $bidRepo);
        $op = $this->createPostOperation();

        $this->expectException(ValidationException::class);
        $processor->process($bid, $op);
    }

    private function createBidProcessor(
        \ApiPlatform\State\ProcessorInterface $persistProcessor,
        Security $security,
        BidRepository $bidRepo,
        ?ProfessionalSubscriptionService $subscriptionService = null,
    ): BidProfessionalProcessor {
        return new BidProfessionalProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $bidRepo,
            $subscriptionService ?? new ProfessionalSubscriptionService(),
        );
    }

    private function createPostOperation(): \ApiPlatform\Metadata\Post
    {
        return new \ApiPlatform\Metadata\Post();
    }
}
