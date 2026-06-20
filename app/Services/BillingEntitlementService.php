<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

class BillingEntitlementService
{
    public function activatePlan(User $user, Plan $plan, ?Carbon $expiresAt = null): UserPlan
    {
        return DB::transaction(function () use ($user, $plan, $expiresAt) {
            $activePlan = $user->userPlans()
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($activePlan && $activePlan->plan_id === $plan->id) {
                $activePlan->update([
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                ]);

                $this->syncPlanRole($user, $plan);

                return $activePlan->fresh();
            }

            $user->userPlans()->where('status', 'active')->update([
                'status' => 'cancelled',
                'expires_at' => $expiresAt ?? now(),
            ]);

            $userPlan = UserPlan::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->syncPlanRole($user, $plan);

            return $userPlan;
        });
    }

    public function deactivateCurrentPlan(User $user, string $status = 'cancelled', ?Carbon $expiresAt = null): void
    {
        DB::transaction(function () use ($user, $status, $expiresAt) {
            $user->userPlans()->where('status', 'active')->update([
                'status' => $status,
                'expires_at' => $expiresAt ?? now(),
            ]);

            $this->syncPlanRole($user, null);
        });
    }

    public function syncFromStripeSubscription(User $user): ?UserPlan
    {
        /** @var Subscription|null $subscription */
        $subscription = $user->subscription('default');

        if (! $subscription) {
            $this->deactivateCurrentPlan($user, 'cancelled');

            return null;
        }

        $stripePriceId = $subscription->stripe_price ?: $subscription->items()->value('stripe_price');
        $plan = $stripePriceId
            ? Plan::where('stripe_price_id', $stripePriceId)->first()
            : null;

        if (! $plan) {
            return null;
        }

        if ($subscription->valid()) {
            return $this->activatePlan($user, $plan, $subscription->ends_at);
        }

        $status = ($subscription->pastDue() || $subscription->incomplete())
            ? 'expired'
            : 'cancelled';

        $this->deactivateCurrentPlan($user, $status, $subscription->ends_at ?? now());

        return null;
    }

    public function syncPlanRole(User $user, ?Plan $plan): void
    {
        $planRoleNames = Plan::query()->pluck('slug')->filter()->values()->all();
        $nonPlanRoles = $user->roles()
            ->whereNotIn('name', $planRoleNames)
            ->pluck('name')
            ->all();

        $roles = $nonPlanRoles;

        if ($plan) {
            $roles[] = $plan->slug;
            $roles = array_values(array_unique($roles));
        }

        $user->syncRoles($roles);
    }
}
