<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Bid;
use App\Entity\ClientProfile;
use App\Entity\Notification;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Entity\VisitRequest;
use App\Enum\BidStatus;
use App\Enum\Category;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $browser;
    protected EntityManagerInterface $em;
    protected JWTTokenManagerInterface $jwtManager;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        $this->browser = static::createClient();
        $this->browser->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        $this->truncateTables();
    }

    protected function authHeaders(User $user): array
    {
        $token = $this->jwtManager->create($user);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    protected function decodeJsonResponse(string $content): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Response is not a JSON object/array.');
        }

        return $decoded;
    }

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $this->truncateWithForeignKeysDisabled($conn);
    }

    private function truncateWithForeignKeysDisabled(Connection $conn): void
    {
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        // NOTE: usar backticks para tablas reservadas en MySQL.
        foreach ([
            'notification',
            'review',
            'request_question',
            'visit_request',
            'bid',
            '`request`',
            'professional_profile',
            'client_profile',
            '`user`',
        ] as $table) {
            $conn->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }

        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function createClientUser(
        string $email,
        ?string $phoneNumber = null,
        bool $verifiedPhone = true,
        ?string $avatar = null,
        ?float $rating = null,
        int $reviewCount = 0,
        bool $notifyRequestActivity = true,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);

        $profile = new ClientProfile();
        $profile->setFullName('Client ' . $email);
        $profile->setPhoneNumber($phoneNumber);
        $profile->setVerifiedPhone($verifiedPhone);
        $profile->setAvatar($avatar);
        $profile->setRating($rating);
        $profile->setReviewCount($reviewCount);
        $profile->setNotifyRequestActivity($notifyRequestActivity);
        $profile->setUser($user);

        $user->setClientProfile($profile);

        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->flush();

        return $user;
    }

    protected function createProfessionalUser(
        string $email,
        array $roles,
        ?string $phoneNumber = null,
        bool $verifiedPhone = true,
        ?string $avatar = null,
        ?float $rating = null,
        int $reviewCount = 0,
        bool $notifyRequestActivity = true,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setRoles($roles);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro ' . $email);
        $profile->setPhoneNumber($phoneNumber);
        $profile->setVerifiedPhone($verifiedPhone);
        $profile->setAvatar($avatar);
        $profile->setRating($rating);
        $profile->setReviewCount($reviewCount);
        $profile->setNotifyRequestActivity($notifyRequestActivity);
        $profile->setUser($user);

        $user->setProfessionalProfile($profile);

        $this->em->persist($user);
        $this->em->persist($profile);
        $this->em->flush();

        return $user;
    }

    protected function createRequest(
        ClientProfile $clientProfile,
        RequestStatus $status,
        RiskLevel $riskLevel,
        string $title = 'Test request',
        ?string $preciseAddress = null,
        ?string $desiredExecutionTime = 'Lo antes posible',
    ): Request {
        $request = new Request();
        $request->setClient($clientProfile);
        $request->setTitle($title);
        $request->setDescription('Test description');
        $request->setEstimatedPriceMin(8000);
        $request->setEstimatedPriceMax(12000);
        $request->setCategory(Category::DIY);
        $request->setRiskLevel($riskLevel);
        $request->setStatus($status);
        $request->setAddress('Calle Test 1');
        $request->setDesiredExecutionTime($desiredExecutionTime);
        if ($preciseAddress !== null) {
            $request->setPreciseAddress($preciseAddress);
        }

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    protected function createBid(Request $request, User $professionalUser, BidStatus $status, int $priceQuote = 100): Bid
    {
        $bid = new Bid();
        $bid->setRequest($request);
        $bid->setProfessional($professionalUser);
        $bid->setPriceQuote($priceQuote);
        $bid->setStatus($status);

        $request->addBid($bid);

        $this->em->persist($bid);
        $this->em->flush();

        return $bid;
    }

    protected function createVisitRequest(Request $request, ProfessionalProfile $professionalProfile, string $status): VisitRequest
    {
        $managedRequest = $request->getId() !== null ? $this->em->find(Request::class, $request->getId()) : null;
        $managedPro = $professionalProfile->getId() !== null ? $this->em->find(ProfessionalProfile::class, $professionalProfile->getId()) : null;

        if (!$managedRequest instanceof Request || !$managedPro instanceof ProfessionalProfile) {
            throw new \RuntimeException('Expected managed Request and ProfessionalProfile for VisitRequest creation.');
        }

        $visit = new VisitRequest();
        $visit->setRequest($managedRequest);
        $visit->setProfessional($managedPro);
        $visit->setStatus($status);

        $this->em->persist($visit);
        $this->em->flush();

        return $visit;
    }

    protected function getNotificationRepository()
    {
        return $this->em->getRepository(Notification::class);
    }
}

