<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\BillingEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class UserPlanController extends Controller
{
    public function __construct(protected BillingEntitlementService $entitlements) {}

    /**
     * Get the authenticated user's active plan.
     */
    public function show(): JsonResponse
    {
        $userPlan = Auth::user()
            ->userPlans()
            ->with('plan')
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $userPlan) {
            return response()->json(['data' => null, 'message' => 'No active plan.'], 404);
        }

        return response()->json(['data' => $userPlan]);
    }

    /**
     * User selects / joins a plan.
     * Paid plans (non-free) are admin-assigned for now; users can only join free plans.
     */
    public function join(Plan $plan): JsonResponse
    {
        $user = Auth::user();

        if (! $plan->is_active) {
            return response()->json(['message' => 'This plan is not available.'], 422);
        }

        if ($plan->billing !== 'free_forever' && ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Paid plans must be activated by an administrator.'], 403);
        }

        $userPlan = $this->entitlements->activatePlan($user, $plan);

        return response()->json([
            'message' => 'You have joined the ' . $plan->name . ' plan.',
            'data'    => $userPlan->load('plan'),
        ], 201);
    }
}
