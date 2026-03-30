<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\RequestStatus;
use PHPUnit\Framework\TestCase;

final class ProfessionalProfileTest extends TestCase
{
    public function testGetCompletedJobsCountsOnlyCompletedRequests(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro Test');
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);

        $clientUser = new User();
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $req1 = $this->createRequest($clientProfile, RequestStatus::COMPLETED);
        $req2 = $this->createRequest($clientProfile, RequestStatus::ACCEPTED);
        $req3 = $this->createRequest($clientProfile, RequestStatus::COMPLETED);

        $pro->addAssignedRequest($req1);
        $pro->addAssignedRequest($req2);
        $pro->addAssignedRequest($req3);

        $this->assertSame(2, $pro->getCompletedJobs());
    }

    public function testGetCompletedJobsReturnsZeroWhenNone(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro Test');
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);

        $this->assertSame(0, $pro->getCompletedJobs());
    }

    public function testGetReviewsReturnsEmptyWhenNoUser(): void
    {
        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro Test');
        // No user set

        $reviews = $pro->getReviews();
        $this->assertIsIterable($reviews);
        $this->assertCount(0, iterator_to_array($reviews));
    }

    private function createRequest(ClientProfile $client, RequestStatus $status): Request
    {
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setClient($client);
        $request->setStatus($status);
        return $request;
    }
}
