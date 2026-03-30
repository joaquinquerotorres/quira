<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\State\BidWithdrawProcessor;
use Doctrine\ORM\EntityManagerInterface as OrmEntityManager;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidWithdrawProcessorTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testWithdrawsBidAndSetsStatusToRejected(): void
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
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Solicitud test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')
            ->with($bid)
            ->willReturn(['status' => BidStatus::PENDING]);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);
        $op = new \ApiPlatform\Metadata\Patch();

        $result = $processor->process($bid, $op);

        $this->assertSame(BidStatus::REJECTED, $result->getStatus());
    }

    public function testWithdrawsBidWhenOriginalDataHasPendingAsString(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::REJECTED);
        $request->addBid($bid);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')
            ->with($bid)
            ->willReturn(['status' => 'PENDING']);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);

        $result = $processor->process($bid, new \ApiPlatform\Metadata\Patch());

        $this->assertSame(BidStatus::REJECTED, $result->getStatus());
    }

    public function testThrowsWhenUserIsNotBidProfessional(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $otherPro = new User();
        $otherPro->setEmail('other@test.com');

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['status' => BidStatus::PENDING]);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($otherPro);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Solo puedes retirar tus propias propuestas');
        $processor->process($bid, new \ApiPlatform\Metadata\Patch());
    }

    public function testThrowsWhenBidWasNotPending(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::REJECTED);
        $request->addBid($bid);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['status' => BidStatus::REJECTED]);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Solo puedes retirar propuestas pendientes');
        $processor->process($bid, new \ApiPlatform\Metadata\Patch());
    }

    public function testThrowsWhenRequestNotPending(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::ACCEPTED);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['status' => BidStatus::PENDING]);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Solo puedes retirar propuestas en solicitudes pendientes');
        $processor->process($bid, new \ApiPlatform\Metadata\Patch());
    }

    public function testThrowsWhenNotLoggedIn(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['status' => BidStatus::PENDING]);

        $entityManager = $this->createMock(OrmEntityManager::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $processor = new BidWithdrawProcessor($persistProcessor, $entityManager, $this->logger, $security);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes estar logueado para retirar una propuesta');
        $processor->process($bid, new \ApiPlatform\Metadata\Patch());
    }
}
