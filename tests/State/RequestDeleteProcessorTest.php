<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\Request;
use App\Entity\RequestQuestion;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Service\SupabaseUploadTicketService;
use App\State\RequestDeleteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class RequestDeleteProcessorTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testDeleteRequestRemovesDependenciesAndMedia(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $client = new ClientProfile();
        $client->setFullName('Cliente Test');
        $client->setUser($clientUser);
        $clientUser->setClientProfile($client);

        $request = new Request();
        $request->setTitle('Solicitud pendiente');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(1000);
        $request->setEstimatedPriceMax(2000);
        $request->setClient($client);
        $request->setStatus(RequestStatus::PENDING);
        $request->setPhotoUrl('https://example.supabase.co/storage/v1/object/public/requests/1_photo.jpg');
        $request->setExtraPhotoUrls([
            'https://example.supabase.co/storage/v1/object/public/requests/1_extra.jpg',
        ]);

        $question = new RequestQuestion();
        $question->setRequest($request);
        $question->setAuthor($clientUser);
        $question->setQuestionText('Pregunta');
        $question->setAnswerMediaUrls([
            'https://example.supabase.co/storage/v1/object/public/requests/1_answer.mp4',
        ]);
        $request->addQuestion($question);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $reviewRepository = $this->createMock(EntityRepository::class);
        $reviewRepository->method('findBy')->with(['request' => $request])->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($reviewRepository);
        $entityManager->expects($this->atLeastOnce())->method('remove');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->with(
                'DELETE',
                $this->callback(fn(string $url): bool => str_contains($url, '/storage/v1/object/requests/')),
                $this->isType('array')
            )
            ->willReturn($response);

        $supabase = new SupabaseUploadTicketService(
            'https://example.supabase.co',
            'service-role-key',
            'avatars',
            'requests',
            $httpClient,
            $this->logger
        );

        $removeProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $removeProcessor
            ->expects($this->once())
            ->method('process')
            ->with($request, $this->isInstanceOf(\ApiPlatform\Metadata\Delete::class))
            ->willReturn(null);

        $processor = new RequestDeleteProcessor($removeProcessor, $security, $entityManager, $supabase, $this->logger);

        $result = $processor->process($request, new \ApiPlatform\Metadata\Delete());
        $this->assertNull($result);
    }

    public function testThrowsWhenStatusIsNotPending(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client2@test.com');
        $client = new ClientProfile();
        $client->setFullName('Cliente Test');
        $client->setUser($clientUser);
        $clientUser->setClientProfile($client);

        $request = new Request();
        $request->setTitle('Solicitud no cancelable');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(1000);
        $request->setEstimatedPriceMax(2000);
        $request->setClient($client);
        $request->setStatus(RequestStatus::ACCEPTED);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $supabase = new SupabaseUploadTicketService(
            'https://example.supabase.co',
            'service-role-key',
            'avatars',
            'requests',
            $this->createMock(HttpClientInterface::class),
            $this->logger
        );
        $removeProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestDeleteProcessor($removeProcessor, $security, $entityManager, $supabase, $this->logger);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Solo puedes cancelar solicitudes pendientes.');
        $processor->process($request, new \ApiPlatform\Metadata\Delete());
    }

    public function testThrowsWhenClientDoesNotOwnRequest(): void
    {
        $owner = new User();
        $owner->setEmail('owner@test.com');
        $ownerClient = new ClientProfile();
        $ownerClient->setFullName('Owner');
        $ownerClient->setUser($owner);
        $owner->setClientProfile($ownerClient);

        $otherUser = new User();
        $otherUser->setEmail('other@test.com');

        $request = new Request();
        $request->setTitle('Solicitud de otro cliente');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(1000);
        $request->setEstimatedPriceMax(2000);
        $request->setClient($ownerClient);
        $request->setStatus(RequestStatus::PENDING);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($otherUser);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $supabase = new SupabaseUploadTicketService(
            'https://example.supabase.co',
            'service-role-key',
            'avatars',
            'requests',
            $this->createMock(HttpClientInterface::class),
            $this->logger
        );
        $removeProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestDeleteProcessor($removeProcessor, $security, $entityManager, $supabase, $this->logger);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Solo puedes cancelar tus propias solicitudes.');
        $processor->process($request, new \ApiPlatform\Metadata\Delete());
    }
}

