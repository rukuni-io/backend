<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;
use Exception;

class StripeController extends Controller
{
    /**
     * Create a new subscription for the authenticated user
     */
    public function createSubscription(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required',
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = $request->user(); // This works with JWT auth
        $plan = Plan::findOrFail($request->plan_id);

        // Ensure the plan has a Stripe price ID
        if (!$plan->stripe_price_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'This plan is not configured for Stripe payments.'
            ], 400);
        }

        try {
            // Create subscription using Cashier
            $subscription = $user->newSubscription('default', $plan->stripe_price_id)
                                ->create($request->payment_method_id);

            return response()->json([
                'status' => 'success',
                'subscription' => $subscription
            ]);
        } catch (Exception $e) {
            Log::error('Stripe subscription error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}