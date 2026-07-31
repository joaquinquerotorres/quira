<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\EagerRelationsExtension;
use App\Entity\Bid;
use App\Entity\Request;
use App\Tests\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class EagerRelationsExtensionTest extends KernelTestCase
{
    public function testRequestEagerLoadJoinsSerializedAssociations(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $extension = new EagerRelationsExtension();

        $qb = $em->createQueryBuilder()
            ->select('o')
            ->from(Request::class, 'o');

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToCollection($qb, $qng, Request::class);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('eager_req_bids', $dql);
        $this->assertStringContainsString('eager_bid_pro_user', $dql);
        $this->assertStringContainsString('eager_bid_pro_profile', $dql);
        $this->assertStringContainsString('eager_req_visits', $dql);
        $this->assertStringContainsString('eager_visit_pro', $dql);
        $this->assertStringContainsString('eager_req_questions', $dql);
        $this->assertStringContainsString('eager_question_author', $dql);
        $this->assertStringContainsString('DISTINCT', strtoupper($dql));
    }

    public function testRequestReusesExistingClientJoinAlias(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $extension = new EagerRelationsExtension();

        $qb = $em->createQueryBuilder()
            ->select('o')
            ->from(Request::class, 'o')
            ->leftJoin('o.client', 'req_client');

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToItem($qb, $qng, Request::class, ['id' => 1]);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('req_client', $dql);
        $this->assertStringNotContainsString('eager_req_client', $dql);
    }

    public function testBidEagerLoadJoinsRequestAndProfessional(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $extension = new EagerRelationsExtension();

        $qb = $em->createQueryBuilder()
            ->select('b')
            ->from(Bid::class, 'b');

        $qng = new \ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator();
        $extension->applyToCollection($qb, $qng, Bid::class);

        $dql = $qb->getDQL();
        $this->assertStringContainsString('eager_bid_request', $dql);
        $this->assertStringContainsString('eager_bid_req_client', $dql);
        $this->assertStringContainsString('eager_bid_professional', $dql);
        $this->assertStringContainsString('eager_bid_professional_profile', $dql);
        $this->assertStringContainsString('DISTINCT', strtoupper($dql));
    }
}
