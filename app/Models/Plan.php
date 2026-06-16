<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// same namespace, explicit import for static analysis
use App\Models\UserPlan;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'tagline',
        'price',
        'currency',
        'billing',
        'features',
        'built_for',
        'max_groups',
        'max_members_per_group',
        'is_active',
        'stripe_price_id',
    ];

    protected $casts = [
        'features' => 'array',
        'built_for' => 'array',
        'is_active' => 'boolean',
        'price' => 'integer',
        'max_groups' => 'integer',
        'max_members_per_group' => 'integer',
    ];

    /**
     * Return price in major currency unit (e.g. 499 → 4.99)
     */
    public function getPriceDecimalAttribute(): float
    {
        return $this->price / 100;
    }

    public function userPlans(): HasMany
    {
        return $this->hasMany(UserPlan::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
