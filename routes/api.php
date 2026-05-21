<?php

use App\Http\Controllers\Api\V1\AllergenController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BadgesController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FeaturedEventController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\McpTokenController;
use App\Http\Controllers\Api\V1\MealPlanController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PointRequestController;
use App\Http\Controllers\Api\V1\PointsController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\RecipeAllergenController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecipeShareController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\RewardsController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\ShoppingListController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserAllergenController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Models\Family;
use App\Models\User;
use App\Services\LicenseEnforcementService;
use App\Services\UpdateCheckService;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth routes (no authentication required)
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/demo-login', [AuthController::class, 'demoLogin'])->middleware('throttle:10,1');
    Route::post('/auth/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:10,1');
    Route::post('/auth/google/confirm-link', [GoogleAuthController::class, 'confirmLink'])->middleware('throttle:5,1');

    // Public config endpoint — tells frontend what services are available
    Route::get('/config', function (LicenseEnforcementService $license) {
        return response()->json([
            'app_name' => config('app.name', 'Kinhold'),
            'self_hosted' => (bool) config('kinhold.self_hosted', false),
            'billing_enabled' => (bool) config('kinhold.billing_enabled', false),
            'services' => [
                'google_oauth' => ! empty(config('services.google.client_id')),
                'google_calendar' => ! empty(config('kinhold.google.client_id')),
                'ai_platform_key' => ! empty(config('kinhold.chatbot.api_key')),
                'mail' => ! empty(config('mail.mailers.'.config('mail.default').'.host'))
                    || config('mail.default') === 'log',
            ],
            'registration' => true,
            'first_boot' => User::count() === 0,
            'demo_available' => Family::where('slug', 'q32-demo-family')->exists(),
            'version' => config('version.current'),
            'update_available' => app(UpdateCheckService::class)->getStatus(),
            'license' => [
                'warn' => $license->shouldWarn(),
                // Only exposed when the banner is active. Hosted Kinhold's family count
                // is business-sensitive and shouldn't leak through this public endpoint;
                // the SPA only reads this field when warn === true anyway.
                'family_count' => $license->shouldWarn() ? $license->familyCount() : null,
                'commercial_license_acknowledged' => $license->acknowledged(),
            ],
        ]);
    });

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/auth/switch-profile', [AuthController::class, 'switchProfile']);
        Route::patch('/user', [AuthController::class, 'updateProfile']);
        Route::post('/user/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('/user/avatar', [AuthController::class, 'deleteAvatar']);
        Route::put('/user/avatar/preset', [AuthController::class, 'setPresetAvatar']);
        Route::post('/user/avatar/google', [AuthController::class, 'restoreGoogleAvatar']);

        // Dashboard config
        Route::get('/user/dashboard', [DashboardController::class, 'show']);
        Route::put('/user/dashboard', [DashboardController::class, 'update']);

        // Google account linking
        Route::get('/auth/google/link', [GoogleAuthController::class, 'linkRedirect']);
        Route::delete('/auth/google/unlink', [GoogleAuthController::class, 'unlink']);

        // Email verification
        Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');

        // Onboarding
        Route::get('/onboarding/status', [OnboardingController::class, 'status']);
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);

        // Family
        Route::prefix('/family')->group(function () {
            Route::get('/', [FamilyController::class, 'show']);
            Route::put('/', [FamilyController::class, 'update']);
            Route::post('/invite', [FamilyController::class, 'invite']);
            Route::post('/members', [FamilyController::class, 'addMember']);
            Route::put('/members/{member}', [FamilyController::class, 'updateMember']);
            Route::delete('/members/{member}', [FamilyController::class, 'removeMember']);
            Route::delete('/', [FamilyController::class, 'deleteFamily'])->middleware('throttle:5,1');
        });

        // Feature endpoints — gated server-side by the billing paywall (#264)
        // in addition to per-module access checks. Keeps DevTools-bypassed
        // paywall users from hitting the API directly.
        Route::middleware('billing.required')->group(function () {

            // Tags (module: tasks)
            Route::prefix('/tags')->middleware('module:tasks')->group(function () {
                Route::get('/', [TagController::class, 'index']);
                Route::post('/', [TagController::class, 'store']);
                Route::put('/{tag}', [TagController::class, 'update']);
                Route::delete('/{tag}', [TagController::class, 'destroy']);
            });

            // Tasks (module: tasks)
            Route::prefix('/tasks')->middleware('module:tasks')->group(function () {
                Route::get('/', [TaskController::class, 'index']);
                Route::post('/', [TaskController::class, 'store']);
                Route::get('/{task}', [TaskController::class, 'show']);
                Route::put('/{task}', [TaskController::class, 'update']);
                Route::delete('/{task}', [TaskController::class, 'destroy']);
                Route::patch('/{task}/complete', [TaskController::class, 'complete']);
                Route::patch('/{task}/uncomplete', [TaskController::class, 'uncomplete']);
            });

            // Vault (module: vault)
            Route::prefix('/vault')->middleware('module:vault')->group(function () {
                Route::get('/categories', [VaultController::class, 'categories']);
                Route::post('/categories', [VaultController::class, 'storeCategory']);
                Route::put('/categories/{category}', [VaultController::class, 'updateCategory']);
                Route::delete('/categories/{category}', [VaultController::class, 'destroyCategory']);
                Route::get('/entries', [VaultController::class, 'index']);
                Route::post('/entries', [VaultController::class, 'store']);
                Route::get('/entries/{entry}', [VaultController::class, 'show']);
                Route::put('/entries/{entry}', [VaultController::class, 'update']);
                Route::delete('/entries/{entry}', [VaultController::class, 'destroy']);
                Route::post('/entries/{entry}/permissions', [VaultController::class, 'grantPermission']);
                Route::delete('/entries/{entry}/permissions/{user}', [VaultController::class, 'revokePermission']);
                Route::post('/entries/{entry}/documents', [VaultController::class, 'uploadDocument']);
                Route::get('/documents/{document}/download', [VaultController::class, 'downloadDocument']);
                Route::delete('/documents/{document}', [VaultController::class, 'deleteDocument']);
            });

            // Calendar (module: calendar)
            Route::prefix('/calendar')->middleware('module:calendar')->group(function () {
                Route::get('/events', [CalendarController::class, 'events']);
                Route::post('/events', [CalendarController::class, 'storeEvent']);
                Route::put('/events/{familyEvent}', [CalendarController::class, 'updateEvent']);
                Route::delete('/events/{familyEvent}', [CalendarController::class, 'destroyEvent']);
                Route::get('/connections', [CalendarController::class, 'connections']);
                Route::post('/connect', [CalendarController::class, 'connect']);
                Route::delete('/connections/{connection}', [CalendarController::class, 'disconnect']);
                Route::post('/subscribe', [CalendarController::class, 'subscribe']);
                Route::post('/sync', [CalendarController::class, 'sync']);
            });

            // Chat (module: chat)
            Route::prefix('/chat')->middleware('module:chat')->group(function () {
                Route::post('/', [ChatController::class, 'send']);
                Route::get('/history', [ChatController::class, 'history']);
            });

            // Points (module: points)
            Route::prefix('/points')->middleware('module:points')->group(function () {
                Route::get('/bank', [PointsController::class, 'bank']);
                Route::get('/leaderboard', [PointsController::class, 'leaderboard']);
                Route::get('/feed', [PointsController::class, 'feed']);
                Route::post('/kudos', [PointsController::class, 'kudos']);
                Route::post('/kudos/{transaction}/stack', [PointsController::class, 'stackKudos']);
                Route::post('/deduct', [PointsController::class, 'deduct']);
                Route::get('/requests', [PointRequestController::class, 'index']);
                Route::post('/request', [PointRequestController::class, 'store']);
                Route::post('/requests/{pointRequest}/approve', [PointRequestController::class, 'approve']);
                Route::post('/requests/{pointRequest}/deny', [PointRequestController::class, 'deny']);
            });

            // Rewards (module: points)
            Route::prefix('/rewards')->middleware('module:points')->group(function () {
                Route::get('/', [RewardsController::class, 'index']);
                Route::post('/', [RewardsController::class, 'store']);
                Route::put('/{reward}', [RewardsController::class, 'update']);
                Route::delete('/{reward}', [RewardsController::class, 'destroy']);
                Route::post('/{reward}/purchase', [RewardsController::class, 'purchase']);
                Route::post('/{reward}/bid', [RewardsController::class, 'bid']);
                Route::get('/{reward}/bids', [RewardsController::class, 'bids']);
                Route::post('/{reward}/close-auction', [RewardsController::class, 'closeAuction']);
                Route::post('/{reward}/cancel-auction', [RewardsController::class, 'cancelAuction']);
                Route::get('/purchases', [RewardsController::class, 'purchases']);
            });

            // Badges (module: badges)
            Route::prefix('/badges')->middleware('module:badges')->group(function () {
                Route::get('/', [BadgesController::class, 'index']);
                Route::post('/', [BadgesController::class, 'store']);
                Route::put('/{badge}', [BadgesController::class, 'update']);
                Route::delete('/{badge}', [BadgesController::class, 'destroy']);
                Route::post('/{badge}/award', [BadgesController::class, 'award']);
                Route::delete('/{badge}/revoke/{user}', [BadgesController::class, 'revoke']);
                Route::get('/earned', [BadgesController::class, 'earned']);
                Route::post('/easter-egg', [BadgesController::class, 'easterEgg']);
            });

            // Recipes (module: food)
            Route::prefix('/recipes')->middleware('module:food')->group(function () {
                Route::get('/', [RecipeController::class, 'index']);
                Route::post('/', [RecipeController::class, 'store']);

                // Import routes (rate-limited, parent-only via form request)
                Route::middleware(['throttle:recipe-import'])->group(function () {
                    Route::post('/import/url', [RecipeController::class, 'importFromUrl']);
                    Route::post('/import/photo', [RecipeController::class, 'importFromPhoto']);
                    Route::post('/import/social', [RecipeController::class, 'importFromSocialMedia']);
                });

                Route::post('/upload-image', [RecipeController::class, 'uploadImage']);

                Route::get('/{recipe}', [RecipeController::class, 'show']);
                Route::put('/{recipe}', [RecipeController::class, 'update']);
                Route::delete('/{recipe}', [RecipeController::class, 'destroy']);
                Route::post('/{recipe}/restore', [RecipeController::class, 'restore']);
                Route::post('/{recipe}/favorite', [RecipeController::class, 'toggleFavorite']);
                Route::get('/{recipe}/cook-logs', [RecipeController::class, 'cookLogs']);
                Route::post('/{recipe}/cook-logs', [RecipeController::class, 'addCookLog']);
                Route::post('/{recipe}/rate', [RecipeController::class, 'rate']);
                Route::get('/{recipe}/ratings', [RecipeController::class, 'ratings']);

                // Fine-grained allergen edits (single-row confirm / change presence / remove)
                Route::post('/{recipe}/allergens', [RecipeAllergenController::class, 'store']);
                Route::patch('/{recipe}/allergens/{allergen}', [RecipeAllergenController::class, 'update']);

                // Family-wide AI allergen backfill (parent only, throttled
                // to 2/day per family to keep Anthropic costs bounded)
                Route::post('/allergens/backfill', [RecipeAllergenController::class, 'backfill'])
                    ->middleware('throttle:allergen-backfill-dispatch');

                // Public sharing (parent only)
                Route::post('/{recipe}/share', [RecipeShareController::class, 'store']);
                Route::patch('/{recipe}/share', [RecipeShareController::class, 'update']);
                Route::delete('/{recipe}/share', [RecipeShareController::class, 'destroy']);
            });

            // Allergens (module: food)
            Route::prefix('/allergens')->middleware('module:food')->group(function () {
                Route::get('/', [AllergenController::class, 'index']);
                Route::post('/', [AllergenController::class, 'store']);
                Route::patch('/{allergen}', [AllergenController::class, 'update']);
                Route::delete('/{allergen}', [AllergenController::class, 'destroy']);
            });

            // Member allergy profiles (module: food)
            Route::prefix('/users/{user}/allergens')->middleware('module:food')->group(function () {
                Route::get('/', [UserAllergenController::class, 'index']);
                Route::put('/', [UserAllergenController::class, 'update']);
                Route::post('/mark-reviewed', [UserAllergenController::class, 'markReviewed']);
            });

            // Shopping (module: food)
            Route::prefix('/shopping')->middleware('module:food')->group(function () {
                // Product catalog autocomplete (read-only global data)
                Route::get('/catalog/search', [ShoppingListController::class, 'searchCatalog']);

                // Staples
                Route::get('/staples', [ShoppingListController::class, 'listStaples']);
                Route::post('/staples', [ShoppingListController::class, 'addStaple']);
                Route::put('/staples/{staple}', [ShoppingListController::class, 'updateStaple']);
                Route::delete('/staples/{staple}', [ShoppingListController::class, 'removeStaple']);
                Route::patch('/staples/{staple}/toggle', [ShoppingListController::class, 'toggleStaple']);

                // Shopping items (accessed directly by item ID)
                Route::put('/items/{shoppingItem}', [ShoppingListController::class, 'updateItem']);
                Route::delete('/items/{shoppingItem}', [ShoppingListController::class, 'removeItem']);
                Route::patch('/items/{shoppingItem}/check', [ShoppingListController::class, 'checkItem']);
                Route::patch('/items/{shoppingItem}/uncheck', [ShoppingListController::class, 'uncheckItem']);
                Route::patch('/items/{shoppingItem}/on-hand', [ShoppingListController::class, 'markOnHand']);
                Route::patch('/items/{shoppingItem}/need', [ShoppingListController::class, 'clearOnHand']);
                Route::post('/items/{shoppingItem}/move', [ShoppingListController::class, 'moveItem']);
                Route::patch('/items/{shoppingItem}/toggle-recurring', [ShoppingListController::class, 'toggleRecurring']);

                // Shopping lists
                Route::get('/lists', [ShoppingListController::class, 'index']);
                Route::post('/lists', [ShoppingListController::class, 'store']);
                Route::get('/lists/{shoppingList}', [ShoppingListController::class, 'show']);
                Route::put('/lists/{shoppingList}', [ShoppingListController::class, 'update']);
                Route::delete('/lists/{shoppingList}', [ShoppingListController::class, 'destroy']);
                Route::post('/lists/{shoppingList}/clear-checked', [ShoppingListController::class, 'clearChecked']);
                Route::post('/lists/{shoppingList}/items', [ShoppingListController::class, 'addItem']);
                Route::post('/lists/{shoppingList}/add-recipe', [ShoppingListController::class, 'addRecipeToList']);
            });

            // Meal Plans (module: food)
            Route::prefix('/meal-plans')->middleware('module:food')->group(function () {
                Route::get('/', [MealPlanController::class, 'index']);
                Route::get('/current', [MealPlanController::class, 'current']);
                Route::post('/', [MealPlanController::class, 'store']);
                Route::get('/presets', [MealPlanController::class, 'presets']);
                Route::post('/presets', [MealPlanController::class, 'storePreset']);
                Route::put('/presets/{preset}', [MealPlanController::class, 'updatePreset']);
                Route::delete('/presets/{preset}', [MealPlanController::class, 'deletePreset']);
                Route::get('/{plan}', [MealPlanController::class, 'show']);
                Route::post('/{plan}/entries', [MealPlanController::class, 'addEntry']);
                Route::get('/{plan}/shopping-preview', [MealPlanController::class, 'previewShoppingList']);
                Route::post('/{plan}/add-to-shopping-list', [MealPlanController::class, 'addToShoppingList']);
                Route::post('/{plan}/generate-shopping-list', [MealPlanController::class, 'generateShoppingList']);
            });

            // Meal Plan Entries (module: food) — top-level for entry-specific operations
            Route::prefix('/meal-plan-entries')->middleware('module:food')->group(function () {
                Route::put('/{entry}', [MealPlanController::class, 'updateEntry']);
                Route::delete('/{entry}', [MealPlanController::class, 'removeEntry']);
                Route::post('/{entry}/move', [MealPlanController::class, 'moveEntry']);
            });

            // Restaurants (module: food)
            Route::prefix('/restaurants')->middleware('module:food')->group(function () {
                Route::get('/', [RestaurantController::class, 'index']);
                Route::post('/', [RestaurantController::class, 'store']);
                Route::post('/import', [RestaurantController::class, 'import']);
                Route::post('/upload-image', [RestaurantController::class, 'uploadImage']);
                Route::get('/{restaurant}', [RestaurantController::class, 'show']);
                Route::put('/{restaurant}', [RestaurantController::class, 'update']);
                Route::delete('/{restaurant}', [RestaurantController::class, 'destroy']);
                Route::post('/{restaurant}/rate', [RestaurantController::class, 'rate']);
            });

            // Featured Events (unified — reads from family_events table)
            Route::prefix('/featured-events')->group(function () {
                Route::get('/', [FeaturedEventController::class, 'index']);
                Route::get('/countdown', [FeaturedEventController::class, 'countdown']);
                Route::post('/', [FeaturedEventController::class, 'store']);
                Route::put('/{familyEvent}', [FeaturedEventController::class, 'update']);
                Route::put('/{familyEvent}/countdown', [FeaturedEventController::class, 'setCountdown']);
                Route::delete('/{familyEvent}', [FeaturedEventController::class, 'destroy']);
            });

        }); // end billing.required group (#264)

        // Settings — NOT under billing.required so paywalled users can still
        // export their data and delete their account (GDPR/CCPA, #265).
        Route::prefix('/settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);
            Route::put('/', [SettingsController::class, 'update']);
            Route::get('/email-preferences', [SettingsController::class, 'emailPreferences']);
            Route::put('/email-preferences', [SettingsController::class, 'updateEmailPreferences']);
            Route::get('/notification-preferences', [SettingsController::class, 'notificationPreferences']);
            Route::put('/notification-preferences', [SettingsController::class, 'updateNotificationPreferences']);
            Route::delete('/account', [SettingsController::class, 'deleteAccount'])->middleware('throttle:5,1');
            Route::post('/account/data-export', [SettingsController::class, 'exportData'])->middleware('throttle:5,1');
            Route::post('/ai/test', [SettingsController::class, 'testAiKey'])->middleware('throttle:5,1');
        });

        // Billing — Stripe Checkout, Customer Portal, cancel/resume.
        // Every action is gated by BILLING_ENABLED + billing-owner check
        // inside the controller. See BillingController::authorizeBilling().
        Route::prefix('/billing')->group(function () {
            Route::get('/current', [BillingController::class, 'current']);
            Route::post('/checkout-session', [BillingController::class, 'checkoutSession'])->middleware('throttle:10,1');
            Route::post('/portal-session', [BillingController::class, 'portalSession'])->middleware('throttle:10,1');
            Route::post('/cancel', [BillingController::class, 'cancel'])->middleware('throttle:5,1');
            Route::post('/resume', [BillingController::class, 'resume'])->middleware('throttle:5,1');
            Route::post('/ai-tier', [BillingController::class, 'selectAiTier'])->middleware('throttle:5,1');
        });

        // Push subscriptions
        Route::prefix('/push/subscriptions')->group(function () {
            Route::post('/', [PushSubscriptionController::class, 'store'])->middleware('throttle:30,1');
            Route::delete('/', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:30,1');
            Route::post('/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1');
            Route::post('/test/{key}', [PushSubscriptionController::class, 'testType'])->middleware('throttle:30,1');
        });

        // MCP Token
        Route::prefix('/mcp')->group(function () {
            Route::get('/token', [McpTokenController::class, 'show']);
            Route::post('/token', [McpTokenController::class, 'store']);
            Route::delete('/token', [McpTokenController::class, 'destroy']);
        });
    });

    // Avatar serving (public — avatars are visible to all family members)
    Route::get('/user/avatar/{userId}', [AuthController::class, 'serveAvatar']);

    // Calendar OAuth callback (public, stateless — Google redirects via GET)
    Route::get('/calendar/callback', [CalendarController::class, 'handleCallback'])->name('api.calendar.callback');

});

// Stripe webhook lives at bare /stripe/webhook (registered in AppServiceProvider::boot)
// to match the URL Stripe is configured to call, with Cashier::ignoreRoutes() preventing
// the package's auto-registered duplicate (#290).
