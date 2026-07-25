<?php

declare(strict_types=1);

namespace App\Tests\TestDoubles;

use App\Service\StripeService;

final class TestStripeService extends StripeService
{
    /** @var list<string> */
    public array $cancelCalledWithCustomerIds = [];

    public function __construct()
    {
        parent::__construct('sk_test_dummy', 'price_dummy', 'price_dummy');
    }

    public function cancelActiveSubscriptionsForCustomer(string $customerId): void
    {
        $this->cancelCalledWithCustomerIds[] = $customerId;
        // no-op (avoid network)
    }

    public function cancelSubscriptionsImmediatelyForCustomer(string $customerId): void
    {
        // no-op (avoid network)
    }
}

