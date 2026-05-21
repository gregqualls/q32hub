<?php

namespace App\Providers;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Jobs\BackfillRecipeAllergens;
use App\Models\Family;
use App\Models\MealPlanEntry;
use App\Models\ShoppingItem;
use App\Policies\MealPlanPolicy;
use App\Policies\ShoppingListPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Disable Cashier's auto-registered /stripe/webhook route so traffic
        // hits our subclass (which writes idempotency rows to webhook_events
        // and fires lifecycle notifications). Without this, both Cashier's
        // route and ours register at /stripe/webhook with the same name and
        // Cashier wins — webhooks 200 but skip our overrides (#290).
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Cashier bills the Family (one billing owner per family) rather than
        // individual users — see #70 / #214. The Billable trait lives on Family.
        Cashier::useCustomerModel(Family::class);

        // Stripe webhook receiver. Registered here (not in routes/api.php) so
        // it lives at the bare /stripe/webhook path — matching the URL Stripe
        // is configured to call — instead of /api/v1/stripe/webhook. Pairs
        // with Cashier::ignoreRoutes() above (#290).
        Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
            ->name('cashier.webhook')
            ->middleware(VerifyWebhookSignature::class);

        // Passport OAuth configuration for MCP server
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::authorizationView('mcp.authorize');

        // Recipe import: 20 requests per hour per family
        RateLimiter::for('recipe-import', function ($request) {
            return Limit::perHour(20)->by($request->user()?->family_id ?? $request->ip());
        });

        // Family-wide allergen backfill: cap dispatching to 2 per day per
        // family so a parent can't burn through the AI budget by spamming.
        RateLimiter::for('allergen-backfill-dispatch', function ($request) {
            return Limit::perDay(2)->by($request->user()?->family_id ?? $request->ip());
        });

        // Per-job Anthropic call rate limiter — drained at 30/min per family
        // by the queue worker even on a large backfill batch (#317).
        RateLimiter::for('allergen-backfill', function (BackfillRecipeAllergens $job) {
            return Limit::perMinute(30)->by('family:'.$job->familyId);
        });

        // ShoppingItem uses ShoppingListPolicy (non-standard naming)
        Gate::policy(ShoppingItem::class, ShoppingListPolicy::class);

        // MealPlanEntry uses MealPlanPolicy (entry-level checks live in the plan policy)
        Gate::policy(MealPlanEntry::class, MealPlanPolicy::class);
    }
}
