<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Security\Voter\RequestAddressVoter;
use App\Repository\VisitRequestRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class RequestAddressVoterTest extends TestCase
{
    private RequestAddressVoter $voter;

    protected function setUp(): void
    {
        $visitRepo = $this->createMock(VisitRequestRepository::class);
        $this->voter = new RequestAddressVoter($visitRepo);
    }

    public function testClientAlwaysCanViewOwnPreciseAddress(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente Test');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);
        $this->setId($clientProfile, 1);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test 1');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setPreciseAddress('Calle Test 1, Piso 2');

        $token = new UsernamePasswordToken($clientUser, 'main', ['ROLE_USER']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $request, [RequestAddressVoter::VIEW_PRECISE_ADDRESS])
        );
    }

    public function testAssignedProfessionalCanViewPreciseAddress(): void
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
        $proProfile->setFullName('Pro Test');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);
        $request->setPreciseAddress('Calle Test 1, Piso 2');

        $token = new UsernamePasswordToken($proUser, 'main', ['ROLE_USER']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $request, [RequestAddressVoter::VIEW_PRECISE_ADDRESS])
        );
    }

    public function testProfessionalWithAcceptedBidCanViewPreciseAddress(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $proUser = new User();
        $proUser->setEmail('pro@test.com');

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setPreciseAddress('Calle Test 1');

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($proUser);
        $bid->setPriceQuote(100);
        $bid->setStatus(BidStatus::ACCEPTED);
        $request->addBid($bid);

        $token = new UsernamePasswordToken($proUser, 'main', ['ROLE_USER']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $request, [RequestAddressVoter::VIEW_PRECISE_ADDRESS])
        );
    }

    public function testRandomUserCannotViewPreciseAddress(): void
    {
        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);
        $this->setId($clientProfile, 1);

        $otherUser = new User();
        $otherUser->setEmail('other@test.com');
        $this->setId($otherUser, 999);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setClient($clientProfile);
        $request->setPreciseAddress('Calle Test 1, Piso 2');

        $token = new UsernamePasswordToken($otherUser, 'main', ['ROLE_USER']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $request, [RequestAddressVoter::VIEW_PRECISE_ADDRESS])
        );
    }

    public function testAnonymousUserCannotViewPreciseAddress(): void
    {
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle Test');
        $request->setPriceAmount(100);
        $request->setPreciseAddress('Calle Test 1');

        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $request, [RequestAddressVoter::VIEW_PRECISE_ADDRESS])
        );
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setValue($entity, $id);
    }
}
