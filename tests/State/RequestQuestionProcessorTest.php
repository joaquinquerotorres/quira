<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\Request;
use App\Entity\RequestQuestion;
use App\Entity\User;
use App\State\RequestQuestionProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RequestQuestionProcessorTest extends TestCase
{
    public function testSetsAuthorOnQuestion(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $request->setClient($clientProfile);

        $question = new RequestQuestion();
        $question->setRequest($request);
        $question->setQuestionText('¿Puedo ver el problema?');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $logger = $this->createMock(LoggerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new RequestQuestionProcessor($persistProcessor, $logger, $security);
        $result = $processor->process($question, new \ApiPlatform\Metadata\Post());

        $this->assertSame($user, $result->getAuthor());
    }

    public function testThrowsWhenNotLoggedIn(): void
    {
        $question = new RequestQuestion();
        $question->setQuestionText('Test?');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new RequestQuestionProcessor($persistProcessor, $logger, $security);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes estar logueado');
        $processor->process($question, new \ApiPlatform\Metadata\Post());
    }
}
