<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\EnsureAdminCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EnsureAdminCommandEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_PASSWORD']);
        putenv('ADMIN_EMAIL');
        putenv('ADMIN_PASSWORD');
        parent::tearDown();
    }

    public function testFailsWhenAdminEmailMissing(): void
    {
        unset($_ENV['ADMIN_EMAIL'], $_SERVER['ADMIN_EMAIL']);
        putenv('ADMIN_EMAIL');
        $_ENV['ADMIN_PASSWORD'] = 'x';
        $_SERVER['ADMIN_PASSWORD'] = 'x';
        putenv('ADMIN_PASSWORD=x');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $tester = new CommandTester(new EnsureAdminCommand($em, $hasher));

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('ADMIN_EMAIL', $tester->getDisplay());
    }

    public function testFailsWhenAdminPasswordMissing(): void
    {
        $_ENV['ADMIN_EMAIL'] = 'a@b.c';
        $_SERVER['ADMIN_EMAIL'] = 'a@b.c';
        putenv('ADMIN_EMAIL=a@b.c');
        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        putenv('ADMIN_PASSWORD');

        $repo = $this->createStub(EntityRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $tester = new CommandTester(new EnsureAdminCommand($em, $hasher));

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('ADMIN_PASSWORD', $tester->getDisplay());
    }
}
