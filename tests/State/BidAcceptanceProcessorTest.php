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
use App\State\BidAcceptanceProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidAcceptanceProcessorTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testAcceptsBidAndSetsRequestStatus(): void
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
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new BidAcceptanceProcessor($persistProcessor, $this->logger, $security);
        $op = new \ApiPlatform\Metadata\Patch();

        $result = $processor->process($bid, $op);

        $this->assertSame(BidStatus::ACCEPTED, $result->getStatus());
        $this->assertSame(RequestStatus::ACCEPTED, $request->getStatus());
        $this->assertSame($proProfile, $request->getAssignedProfessional());
    }

    public function testThrowsWhenNonOwnerClientTriesToAccept(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $otherClient = new User();
        $otherClient->setEmail('other@test.com');
        $otherClientProfile = new ClientProfile();
        $otherClientProfile->setFullName('Otro');
        $otherClientProfile->setUser($otherClient);
        $otherClient->setClientProfile($otherClientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($otherClient);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new BidAcceptanceProcessor($persistProcessor, $this->logger, $security);
        $op = new \ApiPlatform\Metadata\Patch();

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Sólo puedes aceptar ofertas para tus propias solicitudes');
        $processor->process($bid, $op);
    }

    public function testThrowsWhenBidNotPending(): void
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
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setStatus(RequestStatus::PENDING);

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(80);
        $bid->setStatus(BidStatus::REJECTED);
        $request->addBid($bid);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new BidAcceptanceProcessor($persistProcessor, $this->logger, $security);
        $op = new \ApiPlatform\Metadata\Patch();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Sólo se pueden aceptar ofertas pendientes');
        $processor->process($bid, $op);
    }
}
