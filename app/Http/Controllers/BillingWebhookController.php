<?php

namespace App\Http\Controllers;

use App\Services\BillingEntitlementService;

class BillingWebhookController extends \Laravel\Cashier\Http\Controllers\WebhookController
{
    public function __construct(protected BillingEntitlementService $entitlements)
    {
        parent::__construct();
    }

    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        $response = parent::handleCustomerSubscriptionCreated($payload);

        $this->syncEntitlementForCustomer($payload['data']['object']['customer'] ?? null);

        return $response;
    }

    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);

        $this->syncEntitlementForCustomer($payload['data']['object']['customer'] ?? null);

        return $response;
    }

    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $this->syncEntitlementForCustomer($payload['data']['object']['customer'] ?? null);

        return $response;
    }

    protected function handleCustomerDeleted(array $payload)
    {
        $response = parent::handleCustomerDeleted($payload);

        $this->syncEntitlementForCustomer($payload['data']['object']['id'] ?? null);

        return $response;
    }

    protected function handleInvoicePaymentFailed(array $payload)
    {
        $this->syncEntitlementForCustomer($payload['data']['object']['customer'] ?? null);

        return $this->successMethod();
    }

    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        $this->syncEntitlementForCustomer($payload['data']['object']['customer'] ?? null);

        return $this->successMethod();
    }

    protected function syncEntitlementForCustomer(?string $stripeCustomerId): void
    {
        if (! $stripeCustomerId) {
            return;
        }

        $user = $this->getUserByStripeId($stripeCustomerId);

        if (! $user) {
            return;
        }

        $this->entitlements->syncFromStripeSubscription($user);
    }
}
