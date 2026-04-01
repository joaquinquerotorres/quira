<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Service\PhoneComparisonService;
use App\State\ClientProfileOwnerProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class ClientProfileOwnerProcessorTest extends TestCase
{
    public function testAutoVerifiesClientPhoneWhenMatchesVerifiedProfessionalPhone(): void
    {
        $user = new User();
        $user->setEmail('client-auto@test.com');

        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro');
        $pro->setPhoneNumber('+34 600 111 222');
        $pro->setVerifiedPhone(true);
        $pro->setAddress('Calle Pro');
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);

        $previous = new ClientProfile();
        $previous->setFullName('Cliente');
        $previous->setPhoneNumber('699999999');
        $previous->setVerifiedPhone(false);
        $previous->setUser($user);

        $current = new ClientProfile();
        $current->setFullName('Cliente');
        $current->setPhoneNumber('600111222');
        $current->setVerifiedPhone(false);
        $current->setUser($user);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ClientProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            new PhoneComparisonService()
        );

        $result = $processor->process($current, new \ApiPlatform\Metadata\Patch(), [], ['previous_data' => $previous]);
        $this->assertTrue($result->isVerifiedPhone());
    }

    public function testDoesNotAutoVerifyWhenProfessionalPhoneIsNotVerified(): void
    {
        $user = new User();
        $user->setEmail('client-no-auto@test.com');

        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro');
        $pro->setPhoneNumber('600111222');
        $pro->setVerifiedPhone(false);
        $pro->setAddress('Calle Pro');
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);

        $previous = new ClientProfile();
        $previous->setFullName('Cliente');
        $previous->setPhoneNumber('699999999');
        $previous->setVerifiedPhone(false);
        $previous->setUser($user);

        $current = new ClientProfile();
        $current->setFullName('Cliente');
        $current->setPhoneNumber('600111222');
        $current->setVerifiedPhone(true);
        $current->setUser($user);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ClientProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            new PhoneComparisonService()
        );

        $result = $processor->process($current, new \ApiPlatform\Metadata\Patch(), [], ['previous_data' => $previous]);
        $this->assertFalse($result->isVerifiedPhone());
    }
}

