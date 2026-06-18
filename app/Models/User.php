<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use Notifiable;
    use SoftDeletes;
    use HasRoles;
    use HasUuids;
    use Laravel\Cashier\Billable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'mobile',
        'email_verified_at',
        'password_reset_code',
        'password_reset_expires_at',
        'email_verification_sent_at',
        'referral_code',
        'referred_by',
        'referral_points',
        'points',
        'login_streak',
        'last_login_date',
        'extra_group_slots',
        'expo_push_token',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at'  => 'datetime',
        'last_login_date'    => 'date',
        'points'             => 'integer',
        'login_streak'       => 'integer',
        'extra_group_slots'  => 'integer',
    ];

    /**
     * Boot method - generate referral code on user creation
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->referral_code = self::generateUniqueReferralCode($user);
        });
    }

    /**
     * Generate unique referral code
     */
    public static function generateUniqueReferralCode($user): string
    {
        do {
            $code = strtoupper(substr($user->name, 0, 3)) . '-' . strtoupper(Str::random(4));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function routeNotificationForExpoPush(): ?string
    {
        return $this->expo_push_token;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Check if email is verified
     */
    public function isEmailVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Get user's unread notifications
     */
    public function unreadNotifications()
    {
        return $this->notifications()->unread();
    }

    /**
     * Get the number of unread notifications
     */
    public function unreadNotificationsCount(): int
    {
        return $this->unreadNotifications()->count();
    }

    /**
     * Get user's notifications
     */
    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }

    /**
     * All plan subscriptions for this user
     */
    public function userPlans(): HasMany
    {
        return $this->hasMany(UserPlan::class);
    }

    /**
     * Groups this user belongs to
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * The user's current active plan subscription
     */
    public function activePlan(): HasOne
    {
        return $this->hasOne(UserPlan::class)->where('status', 'active')->latestOfMany();
    }

    /**
     * Whether the user can create another group as admin
     */
    public function canCreateGroup(): bool
    {
        $plan = $this->activePlan?->plan;
        if (! $plan) {
            return false;
        }
        $owned = $this->groups()->wherePivot('role', 'admin')->count();
        $limit = $plan->max_groups + ($this->extra_group_slots ?? 0);
        return $owned < $limit;
    }

    /**
     * User who referred this user
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Users that this user has referred (referral records)
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Active referrals only
     */
    public function activeReferrals()
    {
        return $this->referrals()->where('status', Referral::STATUS_ACTIVE);
    }

    /**
     * Pending referrals
     */
    public function pendingReferrals()
    {
        return $this->referrals()->where('status', Referral::STATUS_PENDING);
    }

    /**
     * Point transaction history
     */
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class)->latest();
    }

    /**
     * Get referral statistics
     */
    public function getReferralStats(): array
    {
        return [
            'total_referrals'   => $this->referrals()->count(),
            'active_referrals'  => $this->activeReferrals()->count(),
            'pending_referrals' => $this->pendingReferrals()->count(),
            'total_points'      => $this->pointTransactions()->where('action', \App\Services\PointsService::ACTION_REFERRAL)->sum('points'),
        ];
    }
}
