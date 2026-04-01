<?php

declare(strict_types=1);

namespace App\Tests\Repository;

/**
 * Requires: php bin/console doctrine:database:create --env=test && php bin/console doctrine:migrations:migrate --env=test --no-interaction
 */
use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\Category;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Repository\BidRepository;
use App\Tests\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class BidRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BidRepository $bidRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Limpieza fuerte entre tests para evitar problemas de claves únicas y datos residuales.
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['bid', '`request`', 'professional_profile', 'client_profile', '`user`'] as $table) {
            $conn->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->bidRepository = $this->em->getRepository(Bid::class);
    }

    public function testCountByProfessionalThisMonthExcludesWithdrawnBidsBecauseTheyAreDeleted(): void
    {
        $pro = $this->createProfessional('pro-exclude-withdrawn@test.com');
        $client = $this->createClient('client-withdrawn@test.com');

        $request = $this->createRequest($client, RequestStatus::PENDING);
        $this->em->persist($request);
        $this->em->flush();

        $bidPending = new Bid();
        $bidPending->setRequest($request);
        $bidPending->setProfessional($pro);
        $bidPending->setPriceQuote(50);
        $bidPending->setStatus(BidStatus::PENDING);
        $request->addBid($bidPending);
        $this->em->persist($bidPending);

        $this->em->flush();

        // Simula retirada: ahora la puja se elimina físicamente.
        $this->em->remove($bidPending);
        $this->em->flush();

        $count = $this->bidRepository->countByProfessionalThisMonth($pro);
        $this->assertSame(0, $count, 'Should exclude withdrawn bids because they no longer exist');
    }

    public function testCountByProfessionalThisMonthCountsAcceptedBidOnAcceptedRequest(): void
    {
        $pro = $this->createProfessional('pro-count-accepted@test.com');
        $client = $this->createClient('client-count-accepted@test.com');

        $request = $this->createRequest($client, RequestStatus::ACCEPTED);
        $this->em->persist($request);
        $this->em->flush();

        $bidAccepted = new Bid();
        $bidAccepted->setRequest($request);
        $bidAccepted->setProfessional($pro);
        $bidAccepted->setPriceQuote(60);
        $bidAccepted->setStatus(BidStatus::ACCEPTED);
        $request->addBid($bidAccepted);
        $this->em->persist($bidAccepted);

        $this->em->flush();

        $count = $this->bidRepository->countByProfessionalThisMonth($pro);

        $this->assertSame(1, $count, 'Should count accepted bids on accepted requests');
    }

    public function testCountByProfessionalThisMonthCountsBidsOnCompletedRequests(): void
    {
        $pro = $this->createProfessional('pro-count-completed@test.com');
        $client = $this->createClient('client-completed@test.com');

        $request = $this->createRequest($client, RequestStatus::COMPLETED);
        $this->em->persist($request);
        $this->em->flush();

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($pro);
        $bid->setPriceQuote(50);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);
        $this->em->persist($bid);
        $this->em->flush();

        $count = $this->bidRepository->countByProfessionalThisMonth($pro);
        $this->assertSame(1, $count, 'Should count bids on completed requests');
    }

    public function testCountByProfessionalThisMonthOnlyCountsThisMonth(): void
    {
        $pro = $this->createProfessional('pro-month@test.com');
        $client = $this->createClient('client-month@test.com');

        $request = $this->createRequest($client, RequestStatus::PENDING);
        $this->em->persist($request);
        $this->em->flush();

        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($pro);
        $bid->setPriceQuote(50);
        $bid->setStatus(BidStatus::PENDING);
        $request->addBid($bid);
        $this->em->persist($bid);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE bid SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('first day of last month 12:00:00'))->format('Y-m-d H:i:s'), $bid->getId()]
        );

        $count = $this->bidRepository->countByProfessionalThisMonth($pro);
        $this->assertSame(0, $count, 'Should not count bids from last month');
    }

    public function testCanProfessionalBidThisMonthReturnsFalseWhenAtLimit(): void
    {
        $pro = $this->createProfessional('pro-limit@test.com');
        $clientProfile = $this->createClient('client-limit@test.com')->getClientProfile();

        for ($i = 0; $i < BidRepository::BIDS_MONTHLY_LIMIT_FREE; $i++) {
            $request = $this->createRequestForProfile($clientProfile, RequestStatus::PENDING);
            $this->em->persist($request);
        }
        $this->em->flush();

        $requests = $this->em->getRepository(Request::class)->findBy(
            ['client' => $clientProfile],
            ['id' => 'ASC'],
            BidRepository::BIDS_MONTHLY_LIMIT_FREE
        );

        foreach ($requests as $request) {
            $bid = new Bid();
            $bid->setRequest($request);
            $bid->setProfessional($pro);
            $bid->setPriceQuote(50);
            $bid->setStatus(BidStatus::PENDING);
            $request->addBid($bid);
            $this->em->persist($bid);
        }
        $this->em->flush();

        $canBid = $this->bidRepository->canProfessionalBidThisMonth($pro);
        $this->assertFalse($canBid, 'Should not allow bid when at monthly limit');
    }

    private function createProfessional(string $email): User
    {
        // Ensure idempotency across test runs by removing any existing user with this email.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_FREE']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Test');
        $profile->setPhoneNumber('+34600000000');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);
        $profile->setVerifiedPhone(true);

        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->flush();

        return $user;
    }

    private function createClient(string $email): User
    {
        // Ensure idempotency across test runs by removing any existing user with this email.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);

        $profile = new ClientProfile();
        $profile->setFullName('Client Test');
        $profile->setUser($user);
        $user->setClientProfile($profile);

        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->flush();

        return $user;
    }

    private function createRequest(User $clientUser, RequestStatus $status): Request
    {
        return $this->createRequestForProfile($clientUser->getClientProfile(), $status);
    }

    private function createRequestForProfile(ClientProfile $clientProfile, RequestStatus $status): Request
    {
        $request = new Request();
        $request->setTitle('Solicitud de prueba para tests');
        $request->setDescription('Descripción de prueba');
        $request->setAddress('Calle Test 123');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setCategory(Category::DIY);
        $request->setRiskLevel(RiskLevel::LOW);
        $request->setStatus($status);
        $request->setClient($clientProfile);

        return $request;
    }
}
