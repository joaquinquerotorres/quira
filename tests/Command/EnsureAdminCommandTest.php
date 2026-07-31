<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EnsureAdminCommand;
use App\Entity\User;
use App\Tests\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('database')]
final class EnsureAdminCommandTest extends ApiTestCase
{
    private const EMAIL = 'ensure-admin@test.quira.local';
    private const PASSWORD = 'TestAdminPass-NotInRepo!';

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_PASSWORD']);
        putenv('ADMIN_EMAIL');
        putenv('ADMIN_PASSWORD');
        parent::tearDown();
    }

    public function testCreatesAdminIdempotentAndDoesNotResetPasswordUnlessFlagged(): void
    {
        $this->setAdminEnv(self::EMAIL, self::PASSWORD);

        $tester = $this->commandTester();
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Admin creado', $tester->getDisplay());

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $this->assertInstanceOf(User::class, $user);
        $roles = $user->getRoles();
        $this->assertContains(User::ROLE_ADMIN, $roles);
        $this->assertContains(User::ROLE_CLIENT, $roles);
        $this->assertTrue($user->isVerifiedEmail());
        $this->assertNotNull($user->getClientProfile());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, self::PASSWORD));
        $hashAfterCreate = $user->getPassword();

        // Idempotente: no cambia password sin --reset-password
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('password intacta', $tester->getDisplay());
        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $this->assertSame($hashAfterCreate, $reloaded->getPassword());

        // --reset-password actualiza hash
        $newPassword = 'Rotated-Admin-Pass!';
        $this->setAdminEnv(self::EMAIL, $newPassword);
        $this->assertSame(0, $tester->execute(['--reset-password' => true]));
        $this->em->clear();
        /** @var User $afterReset */
        $afterReset = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        $this->assertTrue($hasher->isPasswordValid($afterReset, $newPassword));
        $this->assertFalse($hasher->isPasswordValid($afterReset, self::PASSWORD));
    }

    public function testNonAdminGets403OnAdminStats(): void
    {
        $plain = $this->createClientUser(
            email: 'no-admin-ensure@test.com',
            phoneNumber: '+34600001999',
            verifiedPhone: true,
        );

        $this->browser->request(
            'GET',
            '/api/admin/stats/overview?from=2026-07-01&to=2026-07-07',
            [],
            [],
            $this->authHeaders($plain)
        );
        $this->assertResponseStatusCodeSame(403);
    }

    public function testEnsuredAdminCanAccessStats(): void
    {
        $email = 'ensure-admin-access@test.quira.local';
        $password = 'Access-Admin-Pass!';
        $this->setAdminEnv($email, $password);

        $tester = $this->commandTester();
        $this->assertSame(0, $tester->execute([]));

        /** @var User $admin */
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertInstanceOf(User::class, $admin);

        $this->browser->request(
            'GET',
            '/api/admin/stats/overview?from=2026-07-01&to=2026-07-07',
            [],
            [],
            $this->authHeaders($admin)
        );
        $this->assertResponseStatusCodeSame(200);
    }

    private function commandTester(): CommandTester
    {
        /** @var EnsureAdminCommand $command */
        $command = static::getContainer()->get(EnsureAdminCommand::class);

        return new CommandTester($command);
    }

    private function setAdminEnv(string $email, string $password): void
    {
        $_ENV['ADMIN_EMAIL'] = $email;
        $_ENV['ADMIN_PASSWORD'] = $password;
        $_SERVER['ADMIN_EMAIL'] = $email;
        $_SERVER['ADMIN_PASSWORD'] = $password;
        putenv('ADMIN_EMAIL='.$email);
        putenv('ADMIN_PASSWORD='.$password);
    }
}
