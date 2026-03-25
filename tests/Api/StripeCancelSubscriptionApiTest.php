<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\TestDoubles\TestStripeService;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class StripeCancelSubscriptionApiTest extends ApiTestCase
{
    public function testCancelSubscriptionSetsCancelAtPeriodEndAndDoesNotChangePaidThroughAtOrRoles(): void
    {
        $pro = $this->createProfessionalUser(
            email: 'pro-stripe-cancel@test.com',
            roles: ['ROLE_USER', 'ROLE_PRO'],
            phoneNumber: '+34600000200',
            verifiedPhone: true,
            notifyRequestActivity: false,
        );
        $pro->setStripeCustomerId('cus_test_123');
        $this->em->flush();

        $profile = $pro->getProfessionalProfile();
        $this->assertNotNull($profile);
        $profile->setPaidThroughAt(new \DateTimeImmutable('2030-01-01'));
        $this->em->flush();

        $beforeRoles = $pro->getRoles();
        $beforePaidThrough = $profile->getPaidThroughAt();

        $this->browser->request(
            'POST',
            '/api/stripe/cancel-subscription',
            [],
            [],
            array_merge($this->authHeaders($pro), ['CONTENT_TYPE' => 'application/json']),
            json_encode(['professionalProfileId' => $profile->getId()], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        $this->em->refresh($profile);
        $this->em->refresh($pro);

        $this->assertTrue($profile->isSubscriptionCancelAtPeriodEnd());
        $this->assertSame($beforeRoles, $pro->getRoles());
        $this->assertEquals($beforePaidThrough, $profile->getPaidThroughAt());

        /** @var TestStripeService $stripe */
        $stripe = static::getContainer()->get(\App\Service\StripeService::class);
        $this->assertContains('cus_test_123', $stripe->cancelCalledWithCustomerIds);
    }
}

