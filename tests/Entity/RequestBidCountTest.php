<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use PHPUnit\Framework\TestCase;

final class RequestBidCountTest extends TestCase
{
    public function testBidCountTracksCollection(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('c@test.com');
        $client = new ClientProfile();
        $client->setFullName('Cliente');
        $client->setUser($clientUser);

        $request = new Request();
        $request->setTitle('Trabajo con propuestas');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(1000);
        $request->setEstimatedPriceMax(2000);
        $request->setClient($client);

        self::assertSame(0, $request->getBidCount());

        $pro = new User();
        $pro->setEmail('p@test.com');
        $bid = new Bid();
        $bid->setProfessional($pro);
        $bid->setPriceQuote(1500);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);

        self::assertSame(1, $request->getBidCount());
    }
}
