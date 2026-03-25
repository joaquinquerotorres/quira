<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\VerificationToken;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('database')]
final class PasswordResetFlowTest extends ApiTestCase
{
    public function testForgotPasswordCreatesPasswordResetTokenAndResetPasswordConsumesIt(): void
    {
        /** @var EntityManagerInterface $em */
        $em = $this->em;

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('reset-flow@test.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, 'oldpass123'));
        $em->persist($user);
        $em->flush();
        $userId = (int) $user->getId();

        $this->browser->request(
            'POST',
            '/api/users/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'reset-flow@test.com'], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $tokenRepo = $em->getRepository(VerificationToken::class);
        /** @var User $managedUser */
        $managedUser = $em->getRepository(User::class)->find($userId);
        $this->assertNotNull($managedUser);

        /** @var VerificationToken[] $tokens */
        $tokens = $tokenRepo->findBy(['user' => $managedUser, 'type' => VerificationToken::TYPE_PASSWORD_RESET]);
        $this->assertCount(1, $tokens);

        $token = $tokens[0]->getToken();
        $this->assertNotEmpty($token);

        $this->browser->request(
            'POST',
            '/api/users/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['token' => $token, 'password' => 'newpass123'], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        // token should be consumed
        $tokensAfter = $tokenRepo->findBy(['user' => $managedUser, 'type' => VerificationToken::TYPE_PASSWORD_RESET]);
        $this->assertCount(0, $tokensAfter);

        // password updated
        /** @var User $updatedUser */
        $updatedUser = $em->getRepository(User::class)->find($userId);
        $this->assertNotNull($updatedUser);
        $this->assertTrue($hasher->isPasswordValid($updatedUser, 'newpass123'));
        $this->assertFalse($hasher->isPasswordValid($updatedUser, 'oldpass123'));
    }

    public function testResetPasswordRejectsInvalidToken(): void
    {
        $this->browser->request(
            'POST',
            '/api/users/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['token' => 'invalid', 'password' => 'newpass123'], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);
    }
}

