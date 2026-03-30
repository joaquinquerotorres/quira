<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Service\GeminiService;
use App\Service\MediaService;
use App\State\RequestClientProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RequestClientProcessorTest extends TestCase
{
    private LoggerInterface $logger;
    private MediaService $mediaService;
    private GeminiService $geminiService;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mediaService = $this->createMock(MediaService::class);
        $this->geminiService = $this->createMock(GeminiService::class);
    }

    public function testSetsClientAndStatusWhenSafe(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Reparar grifo');
        $request->setDescription('Gotea en la cocina');
        $request->setAddress('Calle Test 1');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $this->geminiService->method('checkSafety')->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $result = $processor->process($request, new \ApiPlatform\Metadata\Post());

        $this->assertSame($clientProfile, $result->getClient());
        $this->assertSame(RequestStatus::PENDING, $result->getStatus());
        $this->assertFalse($result->getIsFlagged());
    }

    public function testSetsPendingApprovalWhenUnsafe(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setDescription('Descripción del trabajo');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $this->geminiService->method('checkSafety')->willReturn([
            'is_safe' => false,
            'reason' => 'Contenido inapropiado',
        ]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $result = $processor->process($request, new \ApiPlatform\Metadata\Post());

        $this->assertSame(RequestStatus::PENDING_APPROVAL, $result->getStatus());
        $this->assertTrue($result->getIsFlagged());
        $this->assertSame('Contenido inapropiado', $result->getModerationReason());
    }

    public function testCheckSafetyUsesClientOriginalDescriptionWhenDescriptionEmpty(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Reparar algo');
        $request->setDescription(null);
        $request->setClientOriginalDescription('Texto solo en original');
        $request->setAddress('Calle Test 1');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $this->geminiService
            ->expects($this->once())
            ->method('checkSafety')
            ->with(
                'Reparar algo',
                'Texto solo en original',
                null,
                null,
                null
            )
            ->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $processor->process($request, new \ApiPlatform\Metadata\Post());
    }

    public function testSavesMediaWhenBase64Provided(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setDescription('Con foto');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->photoBase64 = 'data:image/jpeg;base64,/9j/4AAQ';

        $this->mediaService->method('saveRequestMediaFile')
            ->with('data:image/jpeg;base64,/9j/4AAQ', 'requests', 'image')
            ->willReturn('/uploads/requests/img_xyz.jpg');
        $this->geminiService->method('checkSafety')->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $result = $processor->process($request, new \ApiPlatform\Metadata\Post());

        $this->assertSame('/uploads/requests/img_xyz.jpg', $result->getPhotoUrl());
    }

    public function testCanPersistExtraMediaUrls(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test extra media');
        $request->setDescription('Descripción con media extra');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $request->setExtraPhotoUrls(['/uploads/requests/extra_photo_1.jpg']);
        $request->setExtraAudioUrls(['/uploads/requests/extra_audio_1.aac']);
        $request->setExtraVideoUrls(['/uploads/requests/extra_video_1.mp4']);

        $this->geminiService->method('checkSafety')->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $result = $processor->process($request, new \ApiPlatform\Metadata\Post());

        $this->assertSame(['/uploads/requests/extra_photo_1.jpg'], $result->getExtraPhotoUrls());
        $this->assertSame(['/uploads/requests/extra_audio_1.aac'], $result->getExtraAudioUrls());
        $this->assertSame(['/uploads/requests/extra_video_1.mp4'], $result->getExtraVideoUrls());
    }

    public function testThrowsWhenUserNotLoggedIn(): void
    {
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes estar logueado');
        $processor->process($request, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenUserHasNoClientProfile(): void
    {
        $user = new User();
        $user->setEmail('noclient@test.com');
        // No clientProfile

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Tu cuenta no tiene un cliente asociado');
        $processor->process($request, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenPhoneNotVerified(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(false);

        $request = new Request();
        $request->setTitle('Test');
        $request->setDescription('Descripción');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $this->geminiService->method('checkSafety')->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes verificar tu número de teléfono antes de crear una solicitud');
        $processor->process($request, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenPhoneNumberEmpty(): void
    {
        $user = new User();
        $user->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber(null);
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);
        $clientProfile->setVerifiedPhone(true);

        $request = new Request();
        $request->setTitle('Test');
        $request->setDescription('Descripción');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);

        $this->geminiService->method('checkSafety')->willReturn(['is_safe' => true]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestClientProcessor(
            $persistProcessor,
            $this->logger,
            $security,
            $this->mediaService,
            $this->geminiService
        );

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes añadir tu número de teléfono en tu perfil de cliente');
        $processor->process($request, new \ApiPlatform\Metadata\Post());
    }
}
