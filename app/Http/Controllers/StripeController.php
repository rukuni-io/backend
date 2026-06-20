<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Services\BillingEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Throwable;

class StripeController extends Controller
{
    public function __construct(protected BillingEntitlementService $entitlements) {}

    /**
     * Create a SetupIntent so the frontend can safely collect / save a card.
     */
    public function createSetupIntent(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            if (! $user->hasStripeId()) {
                $user->createAsStripeCustomer();
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'setup_intent' => $user->createSetupIntent(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Stripe setup intent error', [
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to create setup intent.',
                'debug' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create a new subscription for the authenticated user
     */
    public function createSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method_id' => 'required',
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = $request->user(); // This works with JWT auth
        $plan = Plan::findOrFail($validated['plan_id']);

        if ($plan->billing === 'free_forever') {
            return response()->json([
                'status' => 'error',
                'message' => 'Free plans should be joined via the plan endpoint.',
            ], 422);
        }

        // Ensure the plan has a Stripe price ID
        if (!$plan->stripe_price_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'This plan is not configured for Stripe payments.'
            ], 400);
        }

        try {
            if (! $user->hasStripeId()) {
                $user->createAsStripeCustomer();
            }

            $user->updateDefaultPaymentMethod($validated['payment_method_id']);

            $activeSubscription = $user->subscription('default');

            if ($activeSubscription && $activeSubscription->active()) {
                $alreadyOnTargetPrice = $activeSubscription->items()
                    ->where('stripe_price', $plan->stripe_price_id)
                    ->exists();

                if ($alreadyOnTargetPrice) {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'You are already subscribed to this plan.',
                        'subscription' => $activeSubscription,
                    ]);
                }

                $activeSubscription->swap($plan->stripe_price_id);
                $subscription = $activeSubscription->fresh();
            } else {
                // Create subscription using Cashier.
                $subscription = $user->newSubscription('default', $plan->stripe_price_id)
                    ->create($validated['payment_method_id']);
            }

            $this->entitlements->activatePlan($user, $plan, $subscription->ends_at);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription is active.',
                'subscription' => $subscription
            ]);
        } catch (IncompletePayment $e) {
            return response()->json([
                'status' => 'requires_action',
                'message' => 'Additional authentication is required to complete payment.',
                'payment' => [
                    'payment_intent' => $e->payment->asStripePaymentIntent(),
                ],
            ], 402);
        } catch (Throwable $e) {
            Log::error('Stripe subscription error', [
                'user_id' => $user?->id,
                'plan_id' => $plan?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create subscription. Please try again.'
            ], 400);
        }
    }

    /**
     * Return the current subscription and active local entitlement.
     */
    public function subscriptionStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $activePlan = $user->userPlans()
            ->with('plan')
            ->where('status', 'active')
            ->latest()
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'subscription' => $user->subscription('default'),
                'active_plan' => $activePlan,
            ],
        ]);
    }

    /**
     * Cancel the active Stripe subscription and local entitlement.
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->subscription('default');

        if (! $subscription || ! $subscription->active()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active subscription found.',
            ], 404);
        }

        try {
            $subscription->cancel();

            $this->entitlements->deactivateCurrentPlan($user, 'cancelled', $subscription->ends_at ?? now());

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription has been cancelled.',
            ]);
        } catch (Throwable $e) {
            Log::error('Stripe cancel subscription error', [
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel subscription. Please try again.',
            ], 400);
        }
    }
}