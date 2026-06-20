<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /**
     * List all active plans (public).
     */
    public function index(): JsonResponse
    {
        $plans = Plan::active()->orderBy('price')->get();

        return response()->json(['data' => $plans]);
    }

    /**
     * Create a new plan (admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:100',
            'slug'                  => 'required|string|max:100|unique:plans,slug',
            'tagline'               => 'nullable|string|max:255',
            'price'                 => 'required|integer|min:0',
            'currency'              => 'nullable|string|size:3',
            'billing'               => 'required|in:free_forever,monthly,yearly',
            'stripe_price_id'       => 'nullable|string|max:255',
            'features'              => 'required|array',
            'features.*'            => 'string',
            'built_for'             => 'nullable|array',
            'built_for.*'           => 'string',
            'max_groups'            => 'required|integer|min:1',
            'max_members_per_group' => 'required|integer|min:1',
            'is_active'             => 'boolean',
        ]);

        $plan = Plan::create($validated);

        return response()->json(['data' => $plan], 201);
    }

    /**
     * Update a plan (admin only).
     */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:100',
            'tagline'               => 'nullable|string|max:255',
            'price'                 => 'sometimes|integer|min:0',
            'currency'              => 'nullable|string|size:3',
            'billing'               => 'sometimes|in:free_forever,monthly,yearly',
            'stripe_price_id'       => 'nullable|string|max:255',
            'features'              => 'sometimes|array',
            'features.*'            => 'string',
            'built_for'             => 'nullable|array',
            'built_for.*'           => 'string',
            'max_groups'            => 'sometimes|integer|min:1',
            'max_members_per_group' => 'sometimes|integer|min:1',
            'is_active'             => 'boolean',
        ]);

        $plan->update($validated);

        return response()->json(['data' => $plan]);
    }

    /**
     * Delete a plan (admin only).
     */
    public function destroy(Plan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }
}
