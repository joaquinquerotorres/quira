<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Review;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\State\ReviewProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ReviewProcessorTest extends TestCase
{
    public function testSetsAuthorAndPersists(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientUser->setRoles(['ROLE_USER']);
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proUser->setRoles(['ROLE_PROFESSIONAL']);
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);
        $request->setStatus(RequestStatus::COMPLETED);

        $review = new Review();
        $review->setScore(5);
        $review->setComment('Excelente');
        $review->setRequest($request);
        $review->setTarget($proUser);

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([$review]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Review::class)->willReturn($repo);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);

        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);
        $result = $processor->process($review, new \ApiPlatform\Metadata\Post());

        $this->assertSame($clientUser, $result->getAuthor());
    }

    public function testThrowsWhenNotLoggedIn(): void
    {
        $review = new Review();
        $review->setScore(5);

        $em = $this->createMock(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes estar logueado');
        $processor->process($review, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenAuthorIsNotParty(): void
    {
        $outsider = new User();
        $outsider->setEmail('outsider@test.com');
        $outsider->setRoles(['ROLE_USER']);

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
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);
        $request->setStatus(RequestStatus::COMPLETED);

        $review = new Review();
        $review->setScore(5);
        $review->setRequest($request);
        $review->setTarget($proUser);

        $em = $this->createMock(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($outsider);
        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);

        $this->expectException(AccessDeniedHttpException::class);
        $processor->process($review, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenRequestNotReviewable(): void
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
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);
        $request->setStatus(RequestStatus::PENDING);

        $review = new Review();
        $review->setScore(5);
        $review->setRequest($request);
        $review->setTarget($proUser);

        $em = $this->createMock(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($clientUser);
        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);

        $this->expectException(BadRequestHttpException::class);
        $processor->process($review, new \ApiPlatform\Metadata\Post());
    }

    public function testPassesThroughNonReviewData(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);
        $result = $processor->process(new \stdClass(), new \ApiPlatform\Metadata\Post());

        $this->assertInstanceOf(\stdClass::class, $result);
    }
}
