<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Public\PublicRecipeController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Google OAuth routes (full-page redirects, not API calls)
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

// Email verification (signed URL from email)
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $user = User::findOrFail($id);

    if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
        return redirect('/login?verify_error=invalid');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    return redirect('/login?verified=1');
})->middleware('signed')->name('verification.verify');

// Google account linking (from Settings, for existing email/password users)
Route::get('/auth/google/link-callback', [GoogleAuthController::class, 'linkCallback'])->name('google.link-callback');

// OAuth login flow for MCP clients (Passport needs a web session).
// Path is /auth/oauth-login — the route name 'login' is what Laravel's
// Authenticate middleware redirects unauthenticated HTML requests to.
// /login itself is unbound so the SPA catch-all serves the SPA login form.
Route::get('/auth/oauth-login', [GoogleAuthController::class, 'oauthLogin'])->name('login');
Route::get('/auth/google/oauth-callback', [GoogleAuthController::class, 'oauthCallback'])->name('google.oauth-callback');

// Public recipe share — server-rendered Blade view with OG tags for social
// unfurl. Must be declared before the SPA catch-all so it wins on `/r/...`.
Route::get('/r/{token}', [PublicRecipeController::class, 'show'])->name('public.recipe');

// SPA catch-all — exclude api/, oauth/, and .well-known/ paths
Route::get('{any}', function () {
    return view('app');
})->where('any', '^(?!api/|oauth/|\.well-known/).*$')->name('spa');
