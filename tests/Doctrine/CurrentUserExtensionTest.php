<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\CurrentUserExtension;
use App\Entity\Bid;
use App\Enum\BidStatus;
use App\Service\ProfessionalSubscriptionService;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Tests\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[Group('database')]
final class CurrentUserExtensionTest extends KernelTestCase
{
    public function testExtensionSupportsRequestAndBidClasses(): void
    {
        $extension = static::getContainer()->get(CurrentUserExtension::class);
        $this->assertInstanceOf(CurrentUserExtension::class, $extension);
    }

    public function testBidItemAllowsProfessionalToSeeOwnBid(): void
    {
        $pro = $this->createProUser();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($pro);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $requestStack = new RequestStack();

        $extension = new CurrentUserExtension($security, $requestStack, new ProfessionalSubscriptionService());

        $qb = $em->createQueryBuilder()
            ->select('b')
            ->from(Bid::class, 'b')
            ->where('b.id = :id')
            ->setParameter('id', 1);

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToItem($qb, $qng, Bid::class, ['id' => 1]);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('professional', $dql);
        $this->assertStringContainsString('bid_c.user', $dql);
        $this->assertStringContainsString('OR', strtoupper($dql));
    }

    /**
     * @group database
     */
    public function testRequestItemAllowsProfessionalWithBidToSeeRequest(): void
    {
        $pro = $this->createProUser();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($pro);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $requestStack = new RequestStack();

        $extension = new CurrentUserExtension($security, $requestStack, new ProfessionalSubscriptionService());

        $qb = $em->createQueryBuilder()
            ->select('r')
            ->from(Request::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', 1);

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToItem($qb, $qng, Request::class, ['id' => 1]);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('bids', $dql);
        $this->assertStringContainsString('req_client', $dql);
        $this->assertStringContainsString('req_pro', $dql);
        $this->assertStringContainsString('status_pending', $dql);
    }

    public function testMyBidsCollectionExcludesRejectedBids(): void
    {
        $pro = $this->createProUser();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($pro);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn(false);

        $requestStack = new RequestStack();
        $httpRequest = new \Symfony\Component\HttpFoundation\Request(query: ['my_bids' => 'true']);
        $requestStack->push($httpRequest);

        $extension = new CurrentUserExtension($security, $requestStack, new ProfessionalSubscriptionService());

        $qb = $em->createQueryBuilder()
            ->select('b')
            ->from(Bid::class, 'b');

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToCollection($qb, $qng, Bid::class);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('b.professional = :current_user', $dql);
        $this->assertStringContainsString('b.status != :my_bids_excluded_status', $dql);
        $this->assertSame(BidStatus::REJECTED, $qb->getParameter('my_bids_excluded_status')?->getValue());
    }

    private function createProUser(): User
    {
        $user = new User();
        $user->setEmail('pro-ext-test@example.com');
        $user->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_FREE']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Test');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        return $user;
    }
}
