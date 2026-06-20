<?php

use App\Http\Controllers\ContributionController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPlanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * API Routes Configuration
 *
 * All routes in this file are prefixed with 'api/'
 */

/**
 * Protected route to get authenticated user details
 * Requires valid authentication token
 */
Route::middleware(['auth:jwt'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/ping', function () {
    return response()->json(['message' => 'pong'], 200);
});

/**
 * Public leads / waitlist endpoint
 */
Route::post('/leads', [LeadController::class, 'store']);

/**
 * Public plan listing
 */
Route::get('/plans', [PlanController::class, 'index']);

/**
 * Public Referral Route
 * Validate referral code before signup
 */
Route::post('/referral/validate', [ReferralController::class, 'validateCode']);

/**
 * Public Support Routes (FAQ - no auth required)
 */
Route::prefix('support')->group(function () {
    // Get all FAQ categories with articles
    Route::get('/faq', [FaqController::class, 'index']);
    // Search FAQ articles
    Route::get('/faq/search', [FaqController::class, 'search']);
    // Get specific category with articles
    Route::get('/faq/{categorySlug}', [FaqController::class, 'category']);
    // Submit article feedback (helpful/not helpful)
    Route::post('/faq/{articleId}/feedback', [FaqController::class, 'articleFeedback']);
    // Get contact info and SLA
    Route::get('/contact', [FaqController::class, 'contactInfo']);
});

/**
 * Authentication Routes Group
 * Prefix: /auth
 */
Route::prefix('auth')->group(function () {
    // User registration
    Route::post('/register', [UserController::class, 'store']);

    // Email verification
    Route::post('/resend-verification', [UserController::class, 'resendEmailVerification']);

    Route::get('/verify', [UserController::class, 'verifyEmail'])->name('verification.verify');

    // User login
    Route::post('/login', [UserController::class, 'login']);

    // Forgot password - request password reset code
    Route::post('/forgot-password', [UserController::class, 'forgotPassword']);

    // Reset password - reset password with code
    Route::post('/reset-password', [UserController::class, 'resetPassword']);

    // User logout (requires authentication)
    Route::get('/logout', [UserController::class, 'logout'])
        ->middleware('auth:api');

    // Refresh token — no auth middleware: expired tokens must be able to reach this endpoint
    Route::get('/refresh', [UserController::class, 'refreshToken']);
});

Route::prefix('user')->middleware(['auth:api'])->group(function () {
    Route::get('/dashboard', [UserController::class, 'dashboard']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
    Route::post('/add-plan', [UserController::class, 'addPlan']);
    Route::get('/points', [UserController::class, 'pointsSummary']);
    Route::post('/points/redeem', [UserController::class, 'redeemPoints']);
    Route::post('/push-token', [UserController::class, 'updatePushToken']);

    /**
     * Billing Routes (authenticated user)
     * Prefix: /user/billing
     */
    Route::prefix('billing')->group(function () {
        Route::get('/setup-intent', [StripeController::class, 'createSetupIntent']);
        Route::post('/subscribe', [StripeController::class, 'createSubscription']);
        Route::get('/subscription', [StripeController::class, 'subscriptionStatus']);
        Route::post('/cancel', [StripeController::class, 'cancelSubscription']);
    });

    /**
     * Referral Routes Group
     * Prefix: /user/referral
     */
    Route::prefix('referral')->group(function () {
        // Get referral dashboard data (code, stats, history, milestones)
        Route::get('/', [ReferralController::class, 'index']);
        // Get referral history only
        Route::get('/history', [ReferralController::class, 'history']);
        // Regenerate referral code
        Route::post('/regenerate-code', [ReferralController::class, 'regenerateCode']);
    });

    /**
     * Notification Routes Group
     * Prefix: /users/notifications
     * Requires JWT authentication
     */
    Route::prefix('notifications')->group(function () {
        // Get all notifications (supports filtering via query params)
        Route::get('/', [NotificationController::class, 'index']);
        // Get specific notification
        Route::get('/{id}', [NotificationController::class, 'show']);
        // Mark notification as read
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
        // Mark all as read
        Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        // Delete notification
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });


    Route::prefix('group')->group(function () {
        // Read-only — no plan required
        Route::get('/', [GroupController::class, 'index']);
        Route::get('/{id}', [GroupController::class, 'show']);

        // Action routes — require an active plan
        Route::post('/store', [GroupController::class, 'store'])
            ->middleware('requires.plan:create_group');

        Route::get('/{id}/accept-invitation', [GroupController::class, 'acceptInvitation'])
            ->middleware('requires.plan');

        // Join request routes
        Route::get('/{id}/send-join-request', [GroupController::class, 'sendJoinRequest'])
            ->middleware('requires.plan');

        // Route::get('/{id}/join-requests', [GroupController::class, 'getPendingJoinRequests']);

        Route::put('/{groupId}/join-requests/{requestId}/approve', [GroupController::class, 'approveJoinRequest'])
            ->middleware('requires.plan');

        Route::put('/{groupId}/join-requests/{requestId}/reject', [GroupController::class, 'rejectJoinRequest'])
            ->middleware('requires.plan');

        // Contribution routes
        Route::post('/{groupId}/contribute', [ContributionController::class, 'submit'])
            ->middleware('requires.plan');
        Route::get('/{groupId}/contributions', [ContributionController::class, 'index']);
        Route::get('/{groupId}/contributions/{id}/proof', [ContributionController::class, 'proof']);

        // Group admin: verify / reject member contributions
        Route::put('/{groupId}/contributions/{id}/verify', [ContributionController::class, 'verify']);
        Route::put('/{groupId}/contributions/{id}/reject', [ContributionController::class, 'reject']);

        // Payout slot order — admin only
        Route::put('/{id}/payout-order', [GroupController::class, 'updatePayoutOrder']);
    });

    /**
     * Support Ticket Routes (authenticated)
     * Prefix: /user/support
     */
    Route::prefix('support')->group(function () {
        // Get all user's tickets
        Route::get('/tickets', [SupportTicketController::class, 'index']);
        // Create new ticket
        Route::post('/tickets', [SupportTicketController::class, 'store']);
        // Get specific ticket details
        Route::get('/tickets/{ticketId}', [SupportTicketController::class, 'show']);
        // Reply to a ticket
        Route::post('/tickets/{ticketId}/reply', [SupportTicketController::class, 'reply']);
        // Escalate a ticket
        Route::post('/tickets/{ticketId}/escalate', [SupportTicketController::class, 'escalate']);
        // Submit feedback on resolved ticket
        Route::post('/tickets/{ticketId}/feedback', [SupportTicketController::class, 'feedback']);
    });

    /**
     * Plan Routes (authenticated user)
     * Prefix: /user/plan
     */
    Route::prefix('plan')->group(function () {
        // Get current user's active plan
        Route::get('/', [UserPlanController::class, 'show']);
        // Select / join a plan (free plans only; paid are admin-assigned)
        Route::post('/{plan}', [UserPlanController::class, 'join']);
    });
});

/**
 * Admin Routes
 * Requires auth + admin role
 */
Route::prefix('admin')->middleware(['auth:api', 'role:admin'])->group(function () {
    // Plan management
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{plan}', [PlanController::class, 'update']);
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);
});
