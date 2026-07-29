<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Review;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase
{
    public function testGetRatingReturnsScore(): void
    {
        $review = new Review();
        $review->setScore(4);

        $this->assertSame(4, $review->getRating());
    }

    public function testGetTextReturnsComment(): void
    {
        $review = new Review();
        $review->setComment('Muy buen trabajo');

        $this->assertSame('Muy buen trabajo', $review->getText());
    }

    public function testGetAuthorDisplayNameReturnsClientFullName(): void
    {
        $author = new User();
        $author->setEmail('author@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Autor Cliente');
        $clientProfile->setUser($author);
        $author->setClientProfile($clientProfile);

        $review = new Review();
        $review->setAuthor($author);

        $this->assertSame('Autor Cliente', $review->getAuthorDisplayName());
    }

    public function testGetAuthorDisplayNameReturnsProfessionalFullName(): void
    {
        $author = new User();
        $author->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Autor Pro');
        $proProfile->setUser($author);
        $author->setProfessionalProfile($proProfile);

        $review = new Review();
        $review->setAuthor($author);

        $this->assertSame('Autor Pro', $review->getAuthorDisplayName());
    }

    public function testGetAuthorDisplayNameReturnsAnonimoWhenNoAuthor(): void
    {
        $review = new Review();

        $this->assertSame('Anónimo', $review->getAuthorDisplayName());
    }

    public function testGetDateReturnsHoyForToday(): void
    {
        $review = new Review();
        $review->setCreatedAt(new \DateTimeImmutable('today'));

        $this->assertSame('Hoy', $review->getDate());
    }

    public function testGetDateReturnsAyerForYesterday(): void
    {
        $review = new Review();
        $review->setCreatedAt(new \DateTimeImmutable('yesterday'));

        $this->assertSame('Ayer', $review->getDate());
    }

    public function testGetDateReturnsFormattedDateForOldReviews(): void
    {
        $review = new Review();
        $review->setCreatedAt(new \DateTimeImmutable('2024-01-15'));

        $this->assertSame('15/01/2024', $review->getDate());
    }

    public function testGetTargetDisplayNameAndRequestTitle(): void
    {
        $target = new User();
        $target->setEmail('target@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Destino Cliente');
        $clientProfile->setUser($target);
        $target->setClientProfile($clientProfile);

        $request = new \App\Entity\Request();
        $request->setTitle('Arreglo de grifo');

        $review = new Review();
        $review->setTarget($target);
        $review->setRequest($request);

        self::assertSame('Destino Cliente', $review->getTargetDisplayName());
        self::assertSame('Arreglo de grifo', $review->getRequestTitle());
    }

    public function testAuthorIsProfessionalUsesUserHelper(): void
    {
        $author = new User();
        $author->setEmail('pro@test.com');
        $author->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL']);
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $proProfile->setUser($author);
        $author->setProfessionalProfile($proProfile);

        $review = new Review();
        $review->setAuthor($author);

        self::assertTrue($review->isAuthorProfessional());
        self::assertTrue($author->isProfessionalActor());

        $clientOnly = new User();
        $clientOnly->setEmail('client@test.com');
        $clientOnly->setRoles(['ROLE_USER']);
        $review2 = new Review();
        $review2->setAuthor($clientOnly);
        self::assertFalse($review2->isAuthorProfessional());
    }
}
