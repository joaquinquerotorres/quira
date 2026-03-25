<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Review;
use App\Entity\Request;
use App\Entity\User;
use App\State\ReviewProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ReviewProcessorTest extends TestCase
{
    public function testSetsAuthorAndPersists(): void
    {
        $author = new User();
        $author->setEmail('author@test.com');
        $author->setRoles(['ROLE_USER']);

        $target = new User();
        $target->setEmail('target@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Target User');
        $clientProfile->setUser($target);
        $target->setClientProfile($clientProfile);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($target);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);

        $review = new Review();
        $review->setScore(5);
        $review->setComment('Excelente');
        $review->setRequest($request);
        $review->setTarget($target);

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([$review]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Review::class)->willReturn($repo);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($author);

        $logger = $this->createMock(LoggerInterface::class);

        $processor = new ReviewProcessor($logger, $em, $security);
        $result = $processor->process($review, new \ApiPlatform\Metadata\Post());

        $this->assertSame($author, $result->getAuthor());
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
