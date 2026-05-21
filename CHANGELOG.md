# Kinhold — Changelog

> Updated at the end of every working session. Newest entries first.

## 2026-05-21 — v1.10.0: Allergens, multi-image recipes, public sharing ([#311](https://github.com/gregqualls/kinhold/issues/311), [#312](https://github.com/gregqualls/kinhold/issues/312))

Six PRs land together as v1.10.0. Two user-visible features: sharable recipes and a severe-stakes allergen system.

**Allergen system ([#312](https://github.com/gregqualls/kinhold/issues/312)).** Big 9 allergens (peanuts, tree nuts, milk, eggs, wheat, soy, fish, shellfish, sesame) are seeded as global allergens; families can add custom ones. Recipes get tri-state tagging per allergen: contains, may-contain (cross-contact), or absent. Family members get a per-user allergen profile that must be explicitly reviewed before it influences filtering (`allergen_profile_reviewed_at`) — the "false-safe" invariant: an un-reviewed profile is treated as "no data," never as "no allergies."

Filtering is invariant-driven: `/api/v1/recipes?safe_for_members[]={id}` returns only recipes where no listed allergen on the recipe overlaps the member's reviewed allergens. `may_contain` counts as unsafe (anaphylaxis-grade default). The meal planner enforces the same invariant: planning a recipe that hits any reviewed member's allergens returns 409 with `requires_acknowledgement: true` plus the list of hits; the parent can re-submit with `acknowledge_allergens: true` to override.

AI-assisted tagging runs through the existing recipe import pipeline. Each allergen prediction lands as `ai_suggested` or `ai_auto` based on confidence; only `ai_auto` (≥0.95) or `human_confirmed` rows count toward filtering. A backfill job queues per-recipe with per-family rate limits (30/min/family on the worker, 2/day/family on dispatch) so importing a large recipe library can't bury the queue.

Architecture decision: [DEC-013](docs/ARCHITECTURE.md) captures the severe-stakes design invariants (false-safe filtering, confidence routing, provenance tracking, mass-assignment hardening).

**Public recipe sharing ([#311](https://github.com/gregqualls/kinhold/issues/311)).** Recipes can be made public via a 22-char base62 share token (~131 bits of entropy, no enumeration). Public URL `/r/{token}` is Blade-rendered server-side so OG tags and indexable HTML are present without booting the SPA. The page intentionally has `noindex,nofollow` — sharing is for the family group chat, not the open web. Optional `share_visible_attribution` reveals the family name in a footer card; off by default. The Vue side has a "Preview what your family will see" link and a share modal with a clipboard copy.

**Multi-image recipes ([#323](https://github.com/gregqualls/kinhold/issues/323)).** Recipes now support multiple images with ordering, captions, and a primary-image marker. Carved out of #311 because cookbook-scan thumbnails would otherwise leak into shared recipes.

**MCP coverage.** `KinholdFood` gains 13 new actions covering allergens (CRUD, profiles), recipe allergen tagging (add/patch/backfill), and recipe sharing (share/share-update/unshare). MCP clients can run the full new surface.

**Public Blade page polish.** Hero image is full-bleed with a custom gradient overlay so the title sits inside the photo instead of beneath it. Ingredient quantities render as fractions ("2 1/4 cups" not "2.25"). Allergens render as Big 9 line-art SVG icons inline with the ingredients, not as emoji badges in the hero. Print stylesheet hides chrome and keeps the recipe legible on paper.

**Security hardening (pre-merge /review pass).** `share_token`, `share_visible_attribution`, and `allergen_profile_reviewed_at` are not in `$fillable` — those fields require explicit `forceFill` calls from the service layer so a malicious payload can't bypass the false-safe filtering invariant. New `UserAllergenPolicy` gates reads (family-scoped) and writes (parent or self). The backfill job validates `family_id` and short-circuits on mismatch as defense-in-depth.

Tests: 443/443 green. New coverage in `AllergenFilteringTest`, `AllergenAiIntegrationTest`, and `PublicRecipeSharingTest`.

## 2026-05-21 — Kudo stacking hardening: TOCTOU fix + notification suppression ([#309](https://github.com/gregqualls/kinhold/issues/309), [#310](https://github.com/gregqualls/kinhold/issues/310))

Two follow-up fixes for the kudo stacking feature shipped in PR #308.

**TOCTOU race (#309):** Concurrent stack requests could both pass the application-level duplicate check before either insert completed, creating two stack rows for the same user on the same kudo. Fixed with a DB-level unique constraint on `(stacked_from_transaction_id, awarded_by)`. The REST controller and MCP tool both catch `UniqueConstraintViolationException` and return the same friendly "You've already +1'd this kudo." message. The existing app-level check is retained as a cheap early-return for the serial case.

**Notification spam (#310):** Each stack fired a fresh `KudosReceivedNotification` to the recipient, identical to the original kudo notification. A kudo recipient with 10 stacks would receive 11 notifications. Fixed by suppressing the notification when the transaction is a stack (i.e., `stacked_from_transaction_id` is set); the original kudo path is unchanged.

Tests: 3 new tests in `KudosStackingTest` — one for the DB constraint race simulation, one confirming stacks produce no notification, and one regression-guarding that original kudos still notify.

## 2026-05-14 — Stack onto someone else's kudo ([#125](https://github.com/gregqualls/kinhold/issues/125))

Members can now "+1" onto an existing kudo from the points feed instead of having to type their own. Each "+1" is a real Kudos transaction (still +1 point to the recipient), but it's linked to the source kudo via a new `stacked_from_transaction_id` column. The feed surfaces the stack count next to the original and a "+1'd" badge once you've stacked it yourself.

Backend:
- New migration adds `stacked_from_transaction_id` (nullable FK, indexed) to `point_transactions`.
- `PointsService::giveKudos` accepts an optional `$stackOnto` source transaction and links the new row.
- `POST /api/v1/points/kudos/{transaction}/stack` enforces: source must be a kudos in your family, must be an original (not a stack), can't stack onto a kudo you gave or received, can't stack twice on the same kudo. Cross-family hits 404.
- `GET /api/v1/points/feed` now returns `stacks_count` (via `withCount`) and a per-page `stacked_by_me` flag (one extra query for the current user's stacks against the visible kudos).
- New `points_stack_kudos (transaction_id*)` action on the `kinhold-points` MCP tool with matching validation.

Frontend:
- `FeedItem.vue` renders a `+1` button on someone else's original kudos when the current user hasn't already stacked it, a `+1'd` badge when they have, and a quiet `+N` count otherwise. Stacked rows describe themselves as "+1'd a kudo from X".
- `pointsStore.stackKudos(id)` action wraps the new endpoint and re-fetches feed/bank/leaderboard.

Tests: `KudosStackingTest` covers stacking, the four blocks (self-received, self-given, double-stack, stack-of-stack), the non-kudos guard, cross-family 404, and the feed's `stacks_count` + `stacked_by_me` shape. 8 tests, 22 assertions.

Demo seeder seeds a handful of stacked kudos on top of the existing seeded kudos so self-hosters see the badges immediately.

## 2026-05-10 — Email verification fix + register payload regression test ([#299](https://github.com/gregqualls/kinhold/issues/299), [#294](https://github.com/gregqualls/kinhold/issues/294))

Real users (Greg's wife on iOS Safari PWA) reported that tapping the email verification link did nothing — landed on a 404 inside the app, account never marked verified. Two distinct bugs presenting as one symptom:

1. **PWA service worker swallowed the verification URL.** The Workbox `NavigationRoute` denylist in [vite.config.js](vite.config.js) excluded `/api/`, `/auth/`, `/login`, `/oauth/`, etc., but not `/email/`. So in any installed PWA, navigation to `/email/verify/{id}/{hash}` was intercepted by the SW, served the cached SPA shell, and `vue-router` fell through to `NotFoundView` (the `/:pathMatch(.*)*` catch-all). The Laravel verification handler at [routes/web.php:24-36](routes/web.php) was never reached. Fix: added `/^\/email\//` to `navigateFallbackDenylist` so the entire server-rendered `/email/` namespace bypasses the SW. Existing PWA installs pick up the new denylist on next launch via the existing `skipWaiting`/`clientsClaim` (#278 fallout).

2. **Successful verification looked like silent failure.** Server-side, the verify route correctly redirected to `/login?verified=1`, but [LoginView.vue](resources/js/views/auth/LoginView.vue) only checked for `verify_error` — `verified=1` was dropped on the floor. Users saw a normal login screen with no banner and assumed nothing happened. Added a green `bg-status-success/10` banner that mirrors the existing red error banner pattern, copy "Email verified. Sign in to continue."

Locked the Laravel side with two new tests in [tests/Feature/EmailVerificationTest.php](tests/Feature/EmailVerificationTest.php): a valid signed URL marks the user verified and redirects to `/login?verified=1`; a corrupted hash redirects to `/login?verify_error=invalid` and leaves the user unverified.

Bundled with [#294](https://github.com/gregqualls/kinhold/issues/294): added [tests/Feature/Auth/RegisterHydratesFullUserTest.php](tests/Feature/Auth/RegisterHydratesFullUserTest.php) to lock the `/api/v1/user` payload shape after `POST /api/v1/register`. Asserts `user.role === 'parent'`, `family.billing_owner_id === user.id`, `family.is_demo === false`, presence of `family.module_access` (with `calendar` key), `family.members` (containing the registering user), and that `family.billing` is populated when `BILLING_ENABLED=true` and `null` when disabled. This is the regression test the v1.9.0 cutover lacked when PR #287 fixed the latent `auth.family` hydration bug.

## 2026-05-10 — Mobile task-complete UX, take three ([#293](https://github.com/gregqualls/kinhold/issues/293))

PR #300 made the toggle fire on a single tap, but the *feel* on the installed PWA was still wrong: tapping a task row felt like "select first, then close" — three taps to mark a task done. Two reasons:

1. **iOS treats first tap as `:hover`.** The row had Tailwind's `hover:bg-surface-sunken` and the checkbox had a custom `:hover` background. On iOS, the first tap fires the hover state instead of the click, so users had to tap again to actually trigger the action.
2. **Row tap opened the detail panel.** `@click="$emit('click', task)"` on the row meant any thumb-tap that wasn't precisely on the 40×40 circle slid up the detail panel — that was the "selecting the task" feeling. The right mobile interaction model is: circle = toggle, three-dots menu = edit. The row body should not be tappable at all.

Fix:

- `tailwind.config.js` — enabled `future.hoverOnlyWhenSupported: true` so all `hover:*` utilities scope to `(hover: hover)` and never fire on iOS first-tap.
- `app.css` — wrapped `.checkbox-custom:hover` rule in `@media (hover: hover)`.
- `TaskItem.vue` — row click now gates on `matchMedia('(hover: hover)')`. On touch devices the row body does nothing; users tap the circle to toggle or the three-dots to edit.
- `TaskItem.vue` — three-dots context menu was `opacity-0 group-hover:opacity-100`, so it was permanently invisible on mobile. Changed to `opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100` — always visible on touch, reveal-on-hover on mouse.

**Optimistic toggle (two-stage):** `tasksStore` now exposes a `pendingToggles` Set. `toggleComplete()` adds the task ID to the set immediately and clears it in `finally`. `TaskCheckbox` accepts a `pending` prop and shows the *opposite* of `checked` while pending, so the visual check flips on tap with zero latency. `completed_at` (which decides list membership) only changes when the API responds, so the row stays visually in place during the request and then animates to the completed list after the server confirms. Removes the ~1s perceived lag without making the row jump prematurely.

Version: 1.9.1 → 1.9.2.

## 2026-05-10 — Mobile task-complete fix, take two ([#293](https://github.com/gregqualls/kinhold/issues/293) follow-up)

PR #298 (this morning) put `touch-action: manipulation` on the inner 24x24 `<button class="checkbox-custom">` and wrapped it in a presentational `<div class="-m-2 p-2">` to expand the hit area to 40x40. That left the 8px padding ring as a plain `<div>` with no `touch-action`, no `cursor: pointer`, and no button semantics, which is exactly where thumbs land. Result: Chrome mobile required a double-tap (300ms zoom-delay holding the first tap), and the installed iOS PWA didn't toggle at all (standalone-mode gesture recognition won't synthesize clicks reliably on a bare `<div>`).

Fix collapses the shim: the `<button class="checkbox-custom">` itself is now the 40x40 hit area (`w-10 h-10` with `touch-action: manipulation`, `cursor-pointer`, `bg-transparent border-0`, and `-webkit-tap-highlight-color: transparent`). The visible 24x24 affordance moves to a child `<span class="checkbox-ring">`. `-m-2` on the button preserves the 24x24 layout footprint so the row geometry is unchanged. The `-m-2 p-2` wrapper in [TaskItem.vue](resources/js/components/tasks/TaskItem.vue) is gone. Hit area, touch-action, cursor, and button semantics now share one element.

## 2026-05-10 — Stripe webhook idempotency fix ([#290](https://github.com/gregqualls/kinhold/issues/290))

After the v1.9.0 cutover, the `webhook_events` idempotency table stayed empty despite live Stripe deliveries — leaving us exposed to duplicate notifications and grace-period double-clocking on retries. Root cause: Cashier auto-registers `POST /stripe/webhook` named `cashier.webhook` in its service provider, while we registered the same URI inside the `api/v1` group at `/api/v1/stripe/webhook` — also named `cashier.webhook`. Stripe is configured to call `/stripe/webhook`, so all production traffic landed on Cashier's controller, which doesn't write the idempotency row or fire our lifecycle notifications.

- `Cashier::ignoreRoutes()` in [AppServiceProvider::register](app/Providers/AppServiceProvider.php) so the package stops auto-registering the duplicate.
- `POST /stripe/webhook` re-registered directly in `boot` with `VerifyWebhookSignature` middleware, so the bare path Stripe calls now lands on `StripeWebhookController`.
- Dropped the redundant `api/v1` route registration and its now-unused import.
- New regression test `test_first_delivery_records_webhook_event_with_processed_at` asserts the row is created with `processed_at` populated on first delivery. Updated existing webhook tests to use the new bare-path URL.

## 2026-05-10 — Mobile task-complete fix ([#293](https://github.com/gregqualls/kinhold/issues/293))

Production blocker: tapping the complete checkbox on a task did nothing on mobile (worked fine on desktop). Root cause: the 24×24 (`w-6 h-6`) checkbox button in `TaskCheckbox` is well below Apple's 44×44 HIG minimum, so mobile thumb taps frequently missed the button and landed on the row's `openDetail` click handler instead, presenting as "tap does nothing" since the detail panel opening visually replaced the row.

- `touch-action: manipulation` added to `.checkbox-custom` to kill iOS Safari's 300ms double-tap-zoom delay so single taps register immediately.
- Expanded the checkbox wrapper hit area in [TaskItem.vue](resources/js/components/tasks/TaskItem.vue) to 40×40 via `-m-2 p-2` with the toggle handler attached, so taps in the padding region also fire the toggle. The inner button keeps its own click-stop handler for direct taps.

GitHub housekeeping: closed [#219](https://github.com/gregqualls/kinhold/issues/219), [#223](https://github.com/gregqualls/kinhold/issues/223), [#224](https://github.com/gregqualls/kinhold/issues/224) -- all functionally shipped pre-cutover but never closed. Reorganized post-launch bugs and ops issues into Phase B, moved inclusivity/i18n features ([#241](https://github.com/gregqualls/kinhold/issues/241), [#242](https://github.com/gregqualls/kinhold/issues/242), [#244](https://github.com/gregqualls/kinhold/issues/244)) to Phase D, parked [#229](https://github.com/gregqualls/kinhold/issues/229) (BYOK multi-provider) in Phase E.

---

## 2026-05-09 — v1.9.0 production cutover ([#286](https://github.com/gregqualls/kinhold/pull/286)–[#289](https://github.com/gregqualls/kinhold/pull/289))

Stripe live mode wired, staging merged to main, `BILLING_ENABLED=true` flipped on production. Kinhold is open for real subscriptions at app.kinhold.app.

**Stripe live setup:**

- Created live Stripe products (Kinhold base plan, AI Add-on, Storage Add-on) and prices via CLI. Live price IDs set as Upsun project-level `env:` vars so Laravel's `env()` picks them up (project-level vars without the `env:` prefix go into `$PLATFORM_VARIABLES` JSON — inaccessible to the app).
- Registered live webhook endpoint at `https://app.kinhold.app/stripe/webhook` (corrected from `kinhold.app` which routes to the Astro marketing site, not the SPA). Manually resent queued events after the URL fix.
- Stripe storage overage price skipped — metered prices require the new Stripe Meter API (deprecated in API version 2025-03-31.basil), which Cashier 16 doesn't support. Storage gracefully no-ops.
- Set `BILLING_TRIAL_DAYS=15` (project-level) to compensate for Stripe's whole-UTC-day rounding (14 configured → 13 displayed at checkout). Set `BILLING_ENABLED=true` on the `main` environment after the PR deployed.

**Bug fixes shipped to production:**

- **[#287](https://github.com/gregqualls/kinhold/pull/287) — register() missing fetchUser() call.** `auth.js` `register()` set `isAuthenticated = true` but never called `fetchUser()`, so `family` only had the minimal `{id, name, invite_code}` registration response. `billing_owner_id` was `undefined`, causing `activeSteps` to exclude `BillingStep` from the onboarding wizard for all new signups. Fix: added `await fetchUser()` after registration success, matching the pattern already used in `login()`.
- **[#288](https://github.com/gregqualls/kinhold/pull/288) — Allow promotion codes in Stripe Checkout.** Added `allow_promotion_codes: true` to the `BillingService::startCheckout()` session builder so discount codes can be entered at checkout.
- **[#289](https://github.com/gregqualls/kinhold/pull/289) — Cancel-anytime copy in BillingStep.** Added "Cancel anytime in Settings → Billing. No long-term commitment." below the trial banner in `BillingStep.vue` for clearer expectations before checkout.

**Infrastructure:**

- All Stripe env vars migrated to Upsun project-level with `env:` prefix (previously environment-level without prefix — not visible to PHP).
- Staging Upsun environment deleted post-merge.

**Issues filed for follow-up:** [#290](https://github.com/gregqualls/kinhold/issues/290) (webhook_events idempotency table not populating), [#291](https://github.com/gregqualls/kinhold/issues/291) (Stripe Meter API for storage overage), [#292](https://github.com/gregqualls/kinhold/issues/292) (post-checkout redirect race — polling vs webhook), [#293](https://github.com/gregqualls/kinhold/issues/293) (mobile task-completion bug — blocker), [#294](https://github.com/gregqualls/kinhold/issues/294) (dashboard featured event countdown widget), [#295](https://github.com/gregqualls/kinhold/issues/295) (Stripe Checkout card entry accessibility).

---

## 2026-05-06 — v1.9.0 staging smoke-test fixes ([#279](https://github.com/gregqualls/kinhold/pull/279)–[#283](https://github.com/gregqualls/kinhold/pull/283))

Five follow-up fixes filed and resolved during the v1.9.0 smoke test on staging. All on `staging` branch, pending the staging→main PR once Stripe live mode is confirmed.

- **[#279](https://github.com/gregqualls/kinhold/pull/279) — Hide "Skip for now" on BillingStep.** `OnboardingView` computed `isBillingStep` gates the skip button so paywalled users can't bypass the billing wizard requirement.
- **[#280](https://github.com/gregqualls/kinhold/pull/280) — Demo session cap 3 → 5.** `config/kinhold.php` `demo_session_limit` raised from 3 (too tight in smoke test) to 5. Configurable via `CHATBOT_DEMO_SESSION_LIMIT`.
- **[#281](https://github.com/gregqualls/kinhold/pull/281) — GDPR escape hatch from SubscriptionPaywall.** Two small text links ("Export my data", "Delete my account") added below "Sign out" in `SubscriptionPaywall.vue`. Routing to `/settings?gdpr=1#your-data` or `#danger`. `App.vue` suppresses the paywall overlay on that scoped route; `SettingsView` hides every non-account section in `gdprMode` so the rest of the app stays gated.
- **[#282](https://github.com/gregqualls/kinhold/pull/282) — Stale-build recovery after deploy ([#278](https://github.com/gregqualls/kinhold/issues/278)).** Workbox config gains `skipWaiting: true`, `clientsClaim: true`, `cleanupOutdatedCaches: true` so a new service worker takes over immediately on the next load. `app.js` listens for `vite:preloadError` and reloads once on chunk-load failure, with a `sessionStorage` guard against infinite reload loops. Verified end-to-end across two consecutive staging deploys.
- **[#283](https://github.com/gregqualls/kinhold/pull/283) — No API caching in service worker.** Runtime cache strategy for `/api/*` changed from `NetworkFirst` (4-second timeout with caching) to `NetworkOnly`. The previous strategy cached `/api/v1/user` responses, causing returning visitors to be auto-logged-in as a previously-cached user and hit CSRF mismatches. Fixed by clearing the `kinhold-api` cache name and switching to `NetworkOnly` — authenticated responses must never be cached.

---

## 2026-05-03 — Demo family billing lockdown + v1.9.0 (v1.9.0, [#238](https://github.com/gregqualls/kinhold/issues/238) / [#70](https://github.com/gregqualls/kinhold/issues/70))

This release closes the #70 Stripe billing umbrella. With #237 (subscription paywall splash) already on main and this PR landing the demo lockdown, every functional gate is in place. The v1.9.0 flip itself is an Upsun env var change (`BILLING_ENABLED=true`), not a code change — tagging the version here makes the release boundary match the milestone.

Last functional gate before the `BILLING_ENABLED=true` flip. Once billing is on in production, the kinhold.app demo family ('q32-demo-family') would otherwise expose a working "Start trial" CTA in Settings, and any visitor clicking it would create a real Stripe customer + checkout session against the demo family record. Nightly `app:refresh-demo` wipes the family server-side, but Stripe-side artifacts accumulate and a mid-day visitor could see "Subscription active" on what's supposed to be a sandbox tour. #238 makes the demo non-billable end-to-end.

**Backend:**

- **`BillingService::isDemoFamily(Family)`** ([app/Services/BillingService.php](app/Services/BillingService.php)) — single source of truth, slug match against the reserved `q32-demo-family` constant the seeder uses.
- **`BillingController::authorizeBilling()`** gains a demo check between the family-presence and billing-owner checks. Returns 403 with "Billing is disabled for the demo. Sign up at kinhold.app to manage a real subscription." across all six endpoints (current, checkout-session, portal-session, cancel, resume, ai-tier).
- **`AuthController::user()`** exposes `family.is_demo: bool` so the SPA doesn't have to know the slug.
- **`OnboardingController::status()`** marks `billing_step_complete: true` for demo families so the wizard wouldn't block on it even if a demo user re-entered onboarding (defensive — demo seed users have `onboarding_completed_at` set already).

**Frontend:**

- **`SettingsView.canSeeBilling`** ([resources/js/views/settings/SettingsView.vue](resources/js/views/settings/SettingsView.vue)) returns `false` for demo families. The whole BillingPanel section disappears from Settings, so demo visitors can't reach a "Start trial" CTA.
- **`OnboardingView.activeSteps`** ([resources/js/views/onboarding/OnboardingView.vue](resources/js/views/onboarding/OnboardingView.vue)) skips `BillingStep` for demo families.

**Tests:** [tests/Feature/Billing/DemoFamilyBillingLockdownTest.php](tests/Feature/Billing/DemoFamilyBillingLockdownTest.php) covers the slug match, all six endpoints returning 403, real-family endpoints still working, the auth payload `is_demo` flag for both demo and real, and the onboarding step short-circuit.

**Closes:** [#238](https://github.com/gregqualls/kinhold/issues/238). Unblocks the v1.9.0 `BILLING_ENABLED=true` flip.

---

## 2026-05-03 — Subscription paywall splash (v1.8.11, [#223](https://github.com/gregqualls/kinhold/issues/223) / [#70](https://github.com/gregqualls/kinhold/issues/70)-I)

Final functional slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella before the v1.9.0 `BILLING_ENABLED=true` flip. 70-G covers new-signup onboarding (pick a plan up front) and 70-H syncs subscription state from Stripe (webhooks + 7-day grace period). Neither gates an existing user from *using* the app once their state turns bad: a family whose trial expired without subscribing, whose card died past the grace window, or whose Stripe status went `past_due`/`unpaid`/`incomplete_expired` could still load every screen and just see a stale BillingPanel buried in Settings. 70-I closes that hole with a non-dismissible SPA-side overlay above the routed view, gated on a server-derived `requires_payment` flag so the gate decisions match what Stripe says is true right now.

**Backend:**

- **`BillingService::paywallReason(Family)`** ([app/Services/BillingService.php](app/Services/BillingService.php)) — new method returns one of `'trial_expired' | 'past_due' | 'cancelled_expired' | null`. Returns `null` (no paywall) for self-host (`BILLING_ENABLED=false`), pre-onboarding families (no `stripe_id`, handled by 70-G's wizard), inside the 7-day dunning grace window (`Family::inGracePeriod()` true), or holding a currently-valid subscription (active, trialing, or cancelled-but-still-in-period via Cashier's `valid()` semantics). Otherwise checks `stripe_status` for `past_due`/`unpaid`/`incomplete_expired`, then `ends_at?->isPast()` for cancelled-expired, then `trial_ends_at?->isPast()` for trial-expired.
- **`BillingService::requiresPayment(Family): bool`** — thin wrapper around `paywallReason()`. The companion the SPA composable's `requiresPayment` ref reads.
- **`BillingService::paywallStatus(Family, ?string $viewerId): ?array`** — compact payload for the `/api/v1/user` shell. Returns `null` when billing is disabled (so self-host gets a clean payload), otherwise the four keys the SPA needs: `requires_payment`, `paywall_reason`, `is_billing_owner`, `billing_owner_name`, plus `cancelled_ends_at` for the cancelled-expired copy. Deliberately skips the `defaultPaymentMethod()` Stripe round-trip that `summary()` makes (every authed page-load would otherwise hit Stripe — too expensive for a shell endpoint).
- **`AuthController::user()`** ([app/Http/Controllers/Api/V1/AuthController.php](app/Http/Controllers/Api/V1/AuthController.php)) gains a `BillingService` constructor injection and emits a new `family.billing` block by calling `paywallStatus($user->family, $user->id)`. Block is `null` for self-host so the SPA composable resolves to `requires_payment=false` everywhere off the hosted product.
- **`BillingService::summary()`** also surfaces `requires_payment` + `paywall_reason` so the BillingPanel + portal flows can share the same derived state without re-implementing the truth table client-side.

**Frontend:**

- **`useBillingGate()`** ([resources/js/composables/useBillingGate.js](resources/js/composables/useBillingGate.js), new) — single source of truth wrapping the auth store's `family.billing` block. Exposes `requiresPayment`, `paywallReason`, `isBillingOwner`, `billingOwnerName`, `cancelledEndsAt` as computed refs.
- **`SubscriptionPaywall.vue`** ([resources/js/components/billing/SubscriptionPaywall.vue](resources/js/components/billing/SubscriptionPaywall.vue), new) — full-screen modal (`fixed inset-0 z-50` over a backdrop-blurred prussian wash). Non-dismissible: no close button, no escape-key listener. Copy variants keyed off `paywallReason`: `trial_expired` ("Welcome back, your trial has ended"), `past_due` ("Your last payment didn't go through"), `cancelled_expired` ("Your subscription ended on {ends_at}"). Primary CTA wires through to the existing `useBillingStore()` actions: `past_due` calls `openPortal()` (Stripe-hosted portal updates the card), `trial_expired` and `cancelled_expired` call `startCheckout()`. Non-billing-owner viewers see the same modal but with a read-only message naming the billing contact instead of a CTA. The only escape hatch is a "Sign out" link that calls `authStore.logout()` and routes to `/login`.
- **`App.vue`** ([resources/js/App.vue](resources/js/App.vue)) mounts `<SubscriptionPaywall>` as a sibling of `<main>` — the routed view stays in the tree (avoids unmount/remount thrash on resolve) but is unreachable behind the overlay. Auth pages (`Login`, `Register`, `Demo`, `Onboarding`, etc.) and the pre-resolution chromeless window are excluded by the existing `isAuthPage` guard so the logout flow can complete without trapping the user.

**Tests** (18 new — total suite 319 → 337):

- `tests/Feature/Billing/BillingServiceRequiresPaymentTest.php` (11): truth-table coverage for `paywallReason()` — billing disabled, no `stripe_id`, active, trialing, in-grace, trial-expired (`trial_ends_at` past), each of `past_due`/`unpaid`/`incomplete_expired`, cancelled-with-`ends_at`-past, cancelled-with-`ends_at`-future.
- `tests/Feature/Billing/SubscriptionPaywallGateTest.php` (7): hits `GET /api/v1/user` and locks the `family.billing` shape the SPA composable consumes — billing owner with expired trial → actionable; non-owner parent same family state → read-only with owner name; active/trialing/in-grace → no paywall; self-host → `family.billing === null`; `past_due` → paywall with `paywall_reason: 'past_due'`.

**Out of scope (deliberately deferred):**

- In-app pre-paywall warnings ("3 days until your trial ends") — already covered by 70-H lifecycle emails.
- Tier-specific paywalls (locking just AI behind an AI plan) — that's 70-D's territory, already shipped.
- Replacing the cancel-flow `window.confirm` — separate issue [#224](https://github.com/gregqualls/kinhold/issues/224).
- Closing [#219](https://github.com/gregqualls/kinhold/issues/219) (70-F landing page) as done-elsewhere on Cloudflare.

**v1.9.0 readiness:** With #223 done, every functional gate in #70 is in place. Remaining work for the public flip: close out #219, address Dependabot alerts, run the umbrella's 10-step end-to-end verification with `BILLING_ENABLED=true` + Stripe test keys, then flip to live keys.

**Files**

- New: `resources/js/components/billing/SubscriptionPaywall.vue`, `resources/js/composables/useBillingGate.js`, `tests/Feature/Billing/BillingServiceRequiresPaymentTest.php`, `tests/Feature/Billing/SubscriptionPaywallGateTest.php`.
- Modified: `app/Services/BillingService.php` (+`paywallReason`/`requiresPayment`/`paywallStatus`, +summary keys), `app/Http/Controllers/Api/V1/AuthController.php` (+`BillingService` injection, +`family.billing` block), `resources/js/App.vue` (+paywall mount, +`useBillingGate`), `config/version.php` (1.8.11).

## 2026-05-03 — Trial includes AI Lite; mid-trial upgrade ends trial early (v1.8.10, [#230](https://github.com/gregqualls/kinhold/issues/230) / [#70](https://github.com/gregqualls/kinhold/issues/70))

Final patch of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella before the v1.9.0 public flip. Settings copy already promised "AI Lite included with trial," but the wiring didn't match: a fresh-trial family who didn't actively pick a tier landed on the global `default_plan` ('free' = 25 msg/day) instead of Lite (50/day), the BillingPanel listed Lite at full price during trial, and picking Standard/Pro mid-trial silently called `addPriceAndInvoice` while Stripe still had the family in `trialing` (proration math wrong, no warning to the user). #230 closes those gaps.

**Backend:**

- **`AiUsageService::planFor()`** ([app/Services/AiUsageService.php](app/Services/AiUsageService.php)) gains a 5th-precedence trial-fallback layer: when `family->subscription('default')?->onTrial()` is true and `chatbot.plan` is unset, return the Lite plan row (50 msg/day). Settings stay null so the trial-end webhook can clear the implicit grant cleanly without diffing. Updated docblock spells out the precedence order: numeric override > family-set slug > **trial fallback** > demo default > global default.
- **`BillingService::aiTierSummary()`** surfaces two new keys to the SPA: `on_trial: bool` and `included_in_trial: 'lite'|null`. The picker reads `included_in_trial` to swap the dollar price for an "Included with trial" sand-toned badge instead of needing extra round-trips to read trial state.
- **`BillingService::selectAiTier()`** branches on `subscription->onTrial()` before the Stripe reconciliation block. **Lite-during-trial** is settings-only (no `addPriceAndInvoice` call) — the trial fallback in `planFor()` already grants Lite limits, and writing the explicit slug honors the user's pick. **Standard/Pro-during-trial** calls `Subscription::endTrial()` first (Cashier-native; sets `trial_ends_at = null` locally and PUTs `trial_end=now` to Stripe), then refreshes the model and falls through to the existing add-price path so the prorated amount bills today instead of waiting for trial_end. A 422 guard on `hasDefaultPaymentMethod()` blocks the upgrade if no PM is on file (Stripe would refuse the immediate invoice anyway).
- **`StripeWebhookController::handleCustomerSubscriptionUpdated()`** ([app/Http/Controllers/Webhooks/StripeWebhookController.php](app/Http/Controllers/Webhooks/StripeWebhookController.php)) overridden to fire on the `trialing → active|past_due` transition (detected via `previous_attributes.status === 'trialing'`). Calls Cashier's parent first to mirror the subscription state into the DB, then row-locks the family and clears `chatbot.plan` if it's null or `'lite'` (the implicit/free-during-trial cases). Explicit Standard/Pro/BYOK picks are preserved — those families already have Stripe items billing them, so resetting the slug would silently drop the wrong limit. Other status transitions (active→past_due, etc.) are ignored. When the cleared plan was an explicit `'lite'` pick (the family clicked Lite during trial and saw it labeled as their plan), a `LiteTrialEndedNotification` fires to the billing owner so the limit drop isn't silent — null-plan families never saw a "Lite" UI affordance, so they don't get the email.
- **`LiteTrialEndedNotification`** ([app/Notifications/Billing/LiteTrialEndedNotification.php](app/Notifications/Billing/LiteTrialEndedNotification.php)) — seventh queued billing notification, gates on `wantsEmail('billing')` like the rest of the family. Branded HTML template at [resources/views/emails/billing/lite-trial-ended.blade.php](resources/views/emails/billing/lite-trial-ended.blade.php) explains the limit change and CTAs back to the billing portal.

**Performance guard:** `AiUsageService::planFor()` short-circuits the trial-fallback subscription lookup when `family->stripe_id` is null (self-hosted, BILLING_ENABLED=false, or pre-checkout families). Avoids loading the `subscriptions` relation per chat message in the hot path — only families that actually went through Stripe checkout pay the lookup cost.

**Frontend:**

- **`BillingPanel.vue`** ([resources/js/components/billing/BillingPanel.vue](resources/js/components/billing/BillingPanel.vue)) — Lite row renders an "Included with trial" badge (sand pill) and "free during trial" detail text when `ai_tier.included_in_trial === 'lite'`. Picking Standard or Pro during the trial opens a `BaseModal` confirmation ("Picking *X* ends your free trial today and starts billing immediately. Your card will be charged the prorated amount.") — only on confirm does the request fire. Off / BYOK / Lite changes still apply instantly. Cancel-subscription `window.confirm` (issue #224) is intentionally untouched and ships in its own focused PR.

**Tests** (12 new — total suite 307 → 319):

- `tests/Unit/AiUsageServiceTest.php` (4): family without `stripe_id` short-circuits even with a stale trialing subscription record (perf-guard regression test); trialing family with no pick → Lite; trialing family with explicit Pro pick → Pro wins; post-trial family with no pick → free (the precedence boundary).
- `tests/Feature/Billing/TrialAiTierTest.php` (8, new): summary surfaces `on_trial` + `included_in_trial`; non-trialing family doesn't get the flag; selecting Lite during trial writes settings without any Stripe call (proves we short-circuit before reconciliation); 422 guard fires when upgrading to Standard mid-trial without a PM; webhook clears explicit `lite` plan on trial→active transition AND fires `LiteTrialEndedNotification`; webhook clears null plan silently (no email — null-plan families never saw a "Lite" label); webhook preserves explicit Pro pick (no email); webhook ignores non-trial transitions (active→past_due, no email).

**Out of scope (deliberately deferred):**

- The Standard/Pro `endTrial` + `addPriceAndInvoice` integration path is not exercised in feature tests — it requires a real Cashier swap which the existing test suite already treats as the Stripe boundary (other AiTierTest cases Mockery::makePartial the service for the same reason). Manual verification covers it under the v1.9.0 flip protocol.
- #224 (replace `window.confirm` in cancel flow) — separate issue with its own focused PR.
- #223 (70-I subscription-required paywall splash) — depends on this slice but ships separately.

**v1.9.0 readiness:** With #230 done, the `BILLING_ENABLED=true` production flip is safe — trial users who don't actively pick a paid AI tier won't be incorrectly charged or capped. v1.9.0 = next merge to main with the env flag flipped.

**Files**

- New: `tests/Feature/Billing/TrialAiTierTest.php`, `app/Notifications/Billing/LiteTrialEndedNotification.php`, `resources/views/emails/billing/lite-trial-ended.blade.php`.
- Modified: `app/Services/AiUsageService.php` (+trial fallback, +stripe_id perf-guard), `app/Services/BillingService.php` (+`on_trial`/`included_in_trial` in summary, +trial branches in `selectAiTier`), `app/Http/Controllers/Webhooks/StripeWebhookController.php` (+`handleCustomerSubscriptionUpdated` override, +`Arr` import, +`LiteTrialEndedNotification` dispatch), `resources/js/components/billing/BillingPanel.vue` (+included badge, +BaseModal upgrade confirm), `tests/Unit/AiUsageServiceTest.php` (+4 tests), `config/version.php` (1.8.10).

## 2026-05-02 — Stripe webhooks + grace period + lifecycle emails (v1.8.9, [#221](https://github.com/gregqualls/kinhold/issues/221) / [#70](https://github.com/gregqualls/kinhold/issues/70)-H)

Seventh slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella, and the last patch before v1.9.0 (the public billing flip). Every prior 70-X slice wrote local state assuming a webhook would eventually sync the truth back from Stripe — without that loop closed, a card decline, a portal-side cancellation, a trial-end, or a successful subscription activation are all invisible to our DB. 70-H delivers the receiver, the idempotency ledger, the 7-day grace-period state machine, and six branded lifecycle emails.

**Backend:**

- **`StripeWebhookController`** ([app/Http/Controllers/Webhooks/StripeWebhookController.php](app/Http/Controllers/Webhooks/StripeWebhookController.php)) extends Cashier's base controller — inherits the `VerifyWebhookSignature` middleware (auto-attached when `STRIPE_WEBHOOK_SECRET` is set in `config/cashier.php`) plus the built-in `customer.subscription.{created,updated,deleted}` handlers that mirror Stripe state into the `subscriptions` table. Wraps `handleWebhook()` with row-locked idempotency: a `(provider, event_id)` row in `webhook_events` is created in a short transaction; on duplicate deliveries we ack 200 and bail. Adds new handlers for `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.trial_will_end`, and overrides `customer.subscription.deleted` to layer on the cancellation email after Cashier's parent runs.
- **Grace period bookkeeping.** `invoice.payment_failed` sets `families.payment_failed_at = now()` exactly once per failure (replays don't restart the clock — the dunning state machine relies on the original timestamp), and dispatches `PaymentFailedNotification` immediately. `invoice.paid` clears `payment_failed_at`, restores any previously downgraded AI tier via `BillingService::selectAiTier()`, and dispatches `SubscriptionResumedNotification`.
- **`kinhold:enforce-grace-period`** ([app/Console/Commands/EnforceGracePeriod.php](app/Console/Commands/EnforceGracePeriod.php)) sweeps families with `payment_failed_at` set, scheduled daily at 03:00 (1hr after the 02:00 storage tally). Day 3 → `PaymentRetryNotification` + `settings.grace_day_3_sent_at` marker. Day 7 → captures current `chatbot.plan` into `settings.ai_plan_before_downgrade`, calls `BillingService::selectAiTier($family, 'off')` to drop the AI line item from Stripe, sets `settings.storage_soft_capped = true` + `settings.downgraded_at`, dispatches `SubscriptionDowngradedNotification`. Each step is gated on its own marker — re-running the command on the same day is a no-op for already-handled families.
- **`webhook_events` table** ([new migration](database/migrations/2026_05_03_000000_create_webhook_events_table.php)) — generic across providers (`provider` + `event_id` unique), so future Plaid/Postmark integrations reuse the dedupe key. UUID PK, `processed_at` written after the inner handler returns successfully.
- **`families.payment_failed_at`** ([new migration](database/migrations/2026_05_03_000001_add_payment_failed_at_to_families_table.php)) nullable timestamp, cast to `datetime` on the model. New `Family` helpers: `billingOwner(): BelongsTo`, `inGracePeriod(): bool`, `gracePeriodDaysElapsed(): int`.
- **Six new branded lifecycle Notifications** under `app/Notifications/Billing/`: `TrialEndingNotification`, `PaymentFailedNotification`, `PaymentRetryNotification`, `SubscriptionDowngradedNotification`, `SubscriptionCancelledNotification`, `SubscriptionResumedNotification`. All `ShouldQueue`, all addressed to `family.billingOwner` (not the family at large — billing actions belong to the designated owner). Each gates on `wantsEmail('billing')`, with `'billing'` added as a new category + type to `config/notifications.php` so the Settings UI picks it up automatically (default opt-in since these are transactional).
- **Branded HTML email templates** under [resources/views/emails/billing/](resources/views/emails/billing/) — one shared `layout.blade.php` (Plus Jakarta Sans wordmark, brand-warm-ivory `#FAF8F5` shell, near-black CTA buttons, gold accent `#C4975A` for the "Restore" button per [BRAND_GUIDE.md](docs/BRAND_GUIDE.md)) plus six per-notification content templates extending it. The footer always links to the billing portal.
- **Webhook route** added to `routes/api.php` outside the `auth:sanctum` group: `POST /api/v1/stripe/webhook` named `cashier.webhook`, signature middleware as the gate, throttle middleware bypassed (Stripe replays could otherwise be 429'd).

**Tests** (15 new, all green — suite 288 → 303, 745 → 791 assertions):

- `tests/Feature/Billing/WebhookTest.php` (8): each handler with a valid HMAC-SHA256 signed request (helper builds the `t=…,v1=…` header from `STRIPE_WEBHOOK_SECRET`), idempotency replay (3× same `event_id` → 1 notification + 1 ledger row), 403 on bad signature, payment-failed not restarting an existing grace clock.
- `tests/Feature/Billing/GracePeriodTest.php` (7): day-3 fires once, day-3 marker prevents resend, day-7 downgrades + captures previous tier (mocking `BillingService::selectAiTier`), day-7 with no AI tier still flags soft-cap + sends email, families without `payment_failed_at` skipped, double-run is idempotent, `inGracePeriod()`/`gracePeriodDaysElapsed()` helpers reflect state correctly.

**Out of scope (deliberately deferred):**

- Refund self-service UI (manual via Stripe dashboard for v1).
- Tax handling, promo codes, custom dunning cadence (use the 0/3/7 default).
- **#230 (Lite-free-during-trial)** ships as a separate slice immediately after — must land before flipping `BILLING_ENABLED=true` so trial users who pick Lite during onboarding aren't charged on day 1.

**Files**

- New: `app/Http/Controllers/Webhooks/StripeWebhookController.php`, `app/Console/Commands/EnforceGracePeriod.php`, `app/Models/WebhookEvent.php`, 6× `app/Notifications/Billing/*Notification.php`, 7× `resources/views/emails/billing/*.blade.php`, 2× migrations, `tests/Feature/Billing/WebhookTest.php`, `tests/Feature/Billing/GracePeriodTest.php`.
- Modified: `app/Models/Family.php` (cast + helpers + `billingOwner()` relation), `routes/api.php` (route), `routes/console.php` (schedule), `config/notifications.php` (billing category + type).

## 2026-05-02 — Onboarding billing step (v1.8.8, [#220](https://github.com/gregqualls/kinhold/issues/220) / [#70](https://github.com/gregqualls/kinhold/issues/70)-G)

Sixth slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. The billing back-end has been complete since 70-E, but new hosted users had no in-flow prompt to pick a plan — the first thing they had to do post-onboarding was open Settings → Billing and find the picker themselves. 70-G drops a `BillingStep` into the existing onboarding wizard so the choice happens *during* the wizard, with a 14-day no-card trial and a Stripe Checkout handoff that reflects the picked AI tier in the very first invoice. 70-F (public landing page) was completed out-of-tree on Cloudflare from a separate repo and is closing as done-elsewhere.

**Backend:**

- **`BillingService::createBaseCheckout()`** ([app/Services/BillingService.php](app/Services/BillingService.php)) gains an optional `?string $aiTier` parameter. Validates against the same `SELECTABLE_TIERS` registry and per-tier `public:true` + `stripe_price_id` gates as `selectAiTier()`. Managed tiers (lite/standard/pro) ride along as a third line item via `$builder->price($priceId)`; off/byok don't add Stripe items. After the Cashier session is created, `families.settings` keys (`ai_mode`, `chatbot.plan`, optional clear of `ai_api_key`) are written via a new private `writeAiTierSettings()` helper — *after* the Stripe call succeeds, mirroring the no-transaction approach already documented at lines 207–211 (network round-trip shouldn't hold a DB transaction; settings write is atomic on its own).
- **`POST /api/v1/billing/checkout-session`** ([BillingController](app/Http/Controllers/Api/V1/BillingController.php)) accepts an optional `ai_tier` field validated `in:off,byok,lite,standard,pro` and forwards to `createBaseCheckout()`. Existing throttle, BILLING_ENABLED gate, and billing-owner authorization unchanged.
- **`GET /api/v1/onboarding/status`** ([OnboardingController](app/Http/Controllers/Api/V1/OnboardingController.php)) gains two new fields the wizard reads: `billing_enabled` (mirror of the public config gate, co-located so the wizard doesn't need a second round-trip) and `billing_step_complete` (true when self-host, OR an active subscription exists, OR the family has already written `chatbot.plan`/`ai_mode=byok`). Replaces the implicit "always rerun the step" behaviour with a positive done-marker that survives reload after Checkout success.

**Frontend:**

- **New `BillingStep.vue`** ([resources/js/views/onboarding/steps/BillingStep.vue](resources/js/views/onboarding/steps/BillingStep.vue)). Mounted between FeaturesStep and CompleteStep in `OnboardingView`'s `activeSteps` computed — gated on `appConfig.billing_enabled && user.id === family.billing_owner_id` so self-hosters and non-owner parents don't see it (step count stays the same as before billing for those flows). Renders:
  - Static base-plan card ($10/mo Hosted Family + storage disclosure).
  - AI tier radio group reusing `summary.ai_tier.tiers` from `BillingService::aiTierSummary()` — automatically filters to `public:true` and disables tiers whose `stripe_price_id` env is unset ("Coming soon" treatment).
  - Dynamic monthly total = base + selected tier price.
  - Trial banner driven by `summary.trial_eligible && summary.trial_days` ("14-day free trial — no card charged until the trial ends.").
  - Already-subscribed short-circuit card when the family hits the step with an active subscription (e.g., webhook beat them back to the wizard) — a single Continue advances normally.
- **`registerContinue` handler** in BillingStep calls `billing.startCheckout({ success, cancel }, { aiTier })` which redirects to Stripe; returns `false` so `OnboardingView.handleContinue()` does not increment `currentStep` locally — the redirect is the advance.
- **`OnboardingView.vue`** reads `?billing=success|cancel` query on mount: success jumps to the last step (CompleteStep) so the user doesn't re-walk Welcome→Features after Checkout; cancel lands back on BillingStep.
- **`useBillingStore().startCheckout(returnUrls, options)`** ([resources/js/stores/billing.js](resources/js/stores/billing.js)) gains an `options.aiTier` second argument that's forwarded as `ai_tier` in the POST body; existing call sites pass nothing and behave identically.
- **`useOnboardingStore().totalSteps`** bumped 6 → 7 (clamp upper bound for `goToStep`); the rendered count still comes from `OnboardingView.activeSteps.length`.

**Tests** (6 new, all green — total suite 288 → 294):

- `tests/Feature/Onboarding/BillingStepTest.php` (5): checkout-session forwards `ai_tier` and persists `chatbot.plan` + `ai_mode` after the mock Stripe success · invalid `ai_tier` → 422 · billing disabled → 403 · unconfigured managed tier (Stripe price ID null) → 422 with settings unchanged · `/onboarding/status` reports `billing_enabled` + `billing_step_complete` correctly across enabled/disabled and pre/post-pick states.
- `tests/Feature/Billing/BillingControllerTest.php` (1 added): `checkout-session` with `ai_tier=standard` calls `BillingService::createBaseCheckout` with the tier as the fourth arg.

**Out of scope (deliberately deferred):**

- Trial-end notification / `trial_will_end` webhook reconciliation → 70-H.
- Storage add-on toggle UI (issue acceptance was informational-only; storage auto-meters today).
- Annual billing toggle ("monthly only for v1" per umbrella).

**Files**

- New: `resources/js/views/onboarding/steps/BillingStep.vue`, `tests/Feature/Onboarding/BillingStepTest.php`
- Modified: `app/Services/BillingService.php` (+`createBaseCheckout` ai_tier param, +`writeAiTierSettings`), `app/Http/Controllers/Api/V1/BillingController.php` (+`ai_tier` validation), `app/Http/Controllers/Api/V1/OnboardingController.php` (+`billing_enabled`, `billing_step_complete`), `resources/js/views/onboarding/OnboardingView.vue` (+BillingStep import + gate + `?billing=` query handling), `resources/js/stores/billing.js` (+`options.aiTier`), `resources/js/stores/onboarding.js` (totalSteps bump), `tests/Feature/Billing/BillingControllerTest.php` (+ai_tier test), `config/version.php` (1.8.8)

## 2026-05-02 — BYOK key UX: test, clear, billing cross-link (v1.8.7, [#218](https://github.com/gregqualls/kinhold/issues/218) / [#70](https://github.com/gregqualls/kinhold/issues/70)-E)

Fifth slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. The BYOK input UI in **AI & Integrations** ([SettingsView.vue:359-514](resources/js/views/settings/SettingsView.vue)) already handled save / mask / show-hide, but a family pasting a key had no way to **verify** it actually worked until they fired off a real chat message — and the only way to remove a saved key was to clear the field and re-save (non-obvious). 70-D's tier picker also left users stranded when they picked **BYOK** with no hint that a key UI existed in another section. This slice closes those gaps without touching the `BILLING_ENABLED` gate (key UI is canonical in AI & Integrations, so self-hosters get it for free).

**New endpoint: `POST /api/v1/settings/ai/test`** ([SettingsController::testAiKey()](app/Http/Controllers/Api/V1/SettingsController.php), [routes/api.php](routes/api.php)). Lives with the rest of `/settings` rather than `/billing` so it works for self-hosters with `BILLING_ENABLED=false`. Throttled 5/min. Parent-only via the existing `update` Family policy. Body: `{api_key}`. Delegates to `AnthropicProvider::validateKey()` and returns the verdict verbatim — **never persists**. Saving stays a separate `PUT /settings` call.

**`AnthropicProvider::validateKey(string): array`** ([app/Services/AiProviders/AnthropicProvider.php](app/Services/AiProviders/AnthropicProvider.php)). Static (no instance needed for an unverified key). Probes Anthropic with `max_tokens: 1` against the default model — total cost ~$0.000001 per check. 5-second timeout. Never throws. Returns:

| Condition | Response |
|---|---|
| 2xx | `['valid' => true]` |
| 401/403 / `authentication_error` / `permission_error` | `['valid' => false, 'error' => 'Invalid API key.']` |
| 429 / `rate_limit_error` | `['valid' => false, 'error' => 'Rate limited — try again in a moment.']` |
| Other 4xx | `['valid' => false, 'error' => '<truncated message>']` |
| `ConnectionException` | `['valid' => false, 'error' => 'Could not reach Anthropic. Please try again.']` |

**SPA: Test + Clear + status display** ([SettingsView.vue](resources/js/views/settings/SettingsView.vue)). Three additions to the existing BYOK panel:

1. **Test key** button (between Reset and Save) — disabled until something is typed; on click, POSTs the in-memory value, never the saved blob (saved keys aren't round-tripped to the client). Inline status badge appears under the input — green check + "Key is valid." or red X + the error message. A `watch(aiConfig.apiKey)` clears the verdict on the next keystroke so a stale "valid" can't linger next to a fresh paste.
2. **Clear saved key** button — only renders when `aiConfig.hasSavedKey === true`. Confirms via `BaseModal` (sized `sm`, danger button) — explicitly **not** `window.confirm` (#224 tracks the existing BillingPanel cancel-flow violation). On confirm, PUTs `ai_api_key: ''` to the existing settings endpoint, which already treats empty string as a clear.
3. **Help text** under the input rewritten per #218 acceptance: "Find your key at [console.anthropic.com](https://console.anthropic.com). We encrypt it at rest and never see your key in plaintext."

**SPA: BillingPanel cross-link** ([BillingPanel.vue](resources/js/components/billing/BillingPanel.vue)). When the active AI tier is `byok`, render one line under the radio group: *"Manage your Anthropic API key in [AI & Integrations](#ai-integrations)."* The existing `SettingsSection` hash-watcher ([SettingsSection.vue:100-112](resources/js/components/settings/SettingsSection.vue)) auto-opens and scrolls — no new routing logic.

**Security reassurance card on the BYOK panel.** Pasting an API key into someone else's web form is a leap of faith. A small green card now sits above the provider picker: *"Your key stays private. Encrypted with AES-256 before it touches the database, decrypted only in memory the instant a chat request runs, and never logged or shown back to you in plaintext — only a masked preview."* Accurate to the implementation — `SettingsController::update` runs Laravel's `encrypt()` (AES-256-CBC keyed by `APP_KEY`) before writing `families.settings['ai_api_key']`, and `SettingsController::maskApiKey` is the only path that ever surfaces a saved key to the SPA.

**Self-host parity for the AI mode tabs.** Caught during Upsun preview smoke (`BILLING_ENABLED=false` instance was still showing the "Use Kinhold AI · Powered by Claude · Included with your trial" tab). On self-hosted instances there is no managed-AI tier — no one is fronting the Anthropic bill — so the two-tab selector and the "we handle it for you" panel are now gated on a `billingEnabled` computed (`appConfig.value?.billing_enabled`). Self-hosters see only the BYOK panel; `aiMode` is force-loaded to `'byok'` regardless of the stored value, and the Test / Clear / Save action row uses `!billingEnabled || aiMode === 'byok'` so the buttons remain visible.

**Tests** (11 new, all green; full suite remains green):

- `AiKeyTestEndpointTest` (6): unauth → 401 · child → 403 · missing `api_key` → 422 · success path → 200 + `valid:true` · 401 from Anthropic → 200 + `valid:false` + "Invalid API key." · key is **not** persisted to `family.settings.ai_api_key` after a successful test.
- `AnthropicValidateKeyTest` (5 unit): 200 → `valid:true` · `authentication_error` → "Invalid API key." · `rate_limit_error` → "Rate limited" · `ConnectionException` → "Could not reach Anthropic" · other 4xx → truncated error message.

**Bonus copy fix:** Replaced misleading "Free in beta" / "free during the beta period" wording in the Kinhold AI tab with trial-aware copy ("Included with your trial" / "Included free with your 14-day trial; pick a paid AI tier from the Billing panel to keep using it after"). Discovered during smoke testing — the panel was promising a beta-period freebie that no longer matches the billing model.

**Out of scope (follow-ups filed):**

- **Multi-provider BYOK (OpenAI + Gemini)** — original product intent was any major LLM, but per [#201](https://github.com/gregqualls/kinhold/issues/201) `AgentService::askWithTools` only has an Anthropic tool-use adapter. Re-exposing other providers today would let users save keys that pass validation but silently fall back to platform Anthropic in chat. Filed [#229 BYOK multi-provider](https://github.com/gregqualls/kinhold/issues/229) as the unblocking work (function-calling JSON-schema adapters for OpenAI/Gemini, revert the [`2026_04_30_120000` normalization](database/migrations/2026_04_30_120000_normalize_stale_ai_provider_settings.php), per-provider validate probes).
- **Trial includes AI Lite; upgrade ends trial early** — the new copy promises a behavior the billing layer doesn't actually implement yet. Filed [#230](https://github.com/gregqualls/kinhold/issues/230) to grant Lite-tier limits during `trialing` status without a Stripe item, expose the AI picker during trial, and wire Standard/Pro selection to `Subscription::endTrial()` + start real billing.

**Files**

- New: `tests/Feature/Settings/AiKeyTestEndpointTest.php`, `tests/Unit/AnthropicValidateKeyTest.php`
- Modified: `app/Services/AiProviders/AnthropicProvider.php` (+`validateKey`), `app/Http/Controllers/Api/V1/SettingsController.php` (+`testAiKey`), `routes/api.php` (POST `/settings/ai/test`), `resources/js/views/settings/SettingsView.vue` (Test + Clear + status + helper text + BaseModal confirm), `resources/js/components/billing/BillingPanel.vue` (BYOK cross-link), `config/version.php` (1.8.7), `docs/ROADMAP.md`

## 2026-05-02 — AI tier purchase wiring + recipe-import usage tracking (v1.8.6, [#217](https://github.com/gregqualls/kinhold/issues/217) / [#70](https://github.com/gregqualls/kinhold/issues/70)-D)

Fourth slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. Connects the **already-built** AI usage infrastructure (`AiUsageDaily`, `AiUsageService`, plan tiers in `config/kinhold.php`) to actual Stripe purchases. Adding/swapping/removing an AI subscription item now flips `families.settings.chatbot.plan` (the slug `AiUsageService::planFor()` reads) so the existing daily-cap enforcement applies the right limit. Still gated behind `BILLING_ENABLED=false` — no production exposure until 70-H ships.

**Settings shape (no migration; `families.settings` is JSON):**

- `settings.ai_mode` — `'kinhold'` | `'byok'` (already used by `AgentService::resolveApiKey`)
- `settings.ai_api_key` — encrypted BYOK key (already used). Cleared when switching to a managed tier.
- `settings.chatbot.plan` — slug ∈ `lite|standard|pro`. Read by `AiUsageService::planFor()` for cap resolution. Cleared on Off/BYOK.

**`BillingService::selectAiTier(Family, tier)`** ([app/Services/BillingService.php](app/Services/BillingService.php)). Single coordinator for the matrix. Tier values: `off`, `byok`, `lite`, `standard`, `pro`.

| Current → Target | Stripe action | Settings writes |
|---|---|---|
| any → `off` | `Subscription::removePrice()` (drops AI item) | `ai_mode='kinhold'`, clears `ai_api_key` and `chatbot.plan` |
| any → `byok` | Drops AI item | `ai_mode='byok'`, clears `chatbot.plan` (key entry is 70-E) |
| off/byok → managed | `Subscription::addPriceAndInvoice()` (prorated) | `ai_mode='kinhold'`, `chatbot.plan=$tier`, clears `ai_api_key` |
| managed → managed | `Subscription::swap([base, storage, $newAi])` (prorated swap) | Updates `chatbot.plan` |

Stripe call goes first, then settings persist on success — the whole sequence is wrapped in `DB::transaction` so a partial failure leaves pre-existing settings intact rather than pointing at a tier whose Stripe item never landed.

Pre-flight gates (all 422 except billing-disabled which is 403):

- Family has no active base subscription → "Subscribe to the base plan before adding an AI tier."
- Tier slug not in the registry → "Unknown AI tier."
- Target tier not marked `public: true` or `stripe_price_id` empty → "That AI tier is not available yet."

**`POST /api/v1/billing/ai-tier`** ([routes/api.php](routes/api.php), [BillingController::selectAiTier()](app/Http/Controllers/Api/V1/BillingController.php)). Mirrors the existing billing-endpoint pattern — `authorizeBilling()` guard (BILLING_ENABLED + billing_owner), throttle:5,1, returns the fresh `BillingService::summary()` so the SPA reconciles in one round-trip.

**`BillingService::summary()`** now returns an `ai_tier` block with `mode`, `plan`, the existing `usage` payload (count/limit/remaining/reset_at), and a data-driven `tiers` catalogue — only public plans, with a `configured` flag so the SPA can disable rows whose Stripe price ID isn't set yet ("Coming soon" state).

**SPA: tier picker in `BillingPanel`** ([resources/js/components/billing/BillingPanel.vue](resources/js/components/billing/BillingPanel.vue)). Five-radio group between the storage card and the action buttons: **Off · BYOK · Lite · Standard · Pro**. Picker is hidden until the family has an active base subscription (replaced with a "Subscribe to a plan to add an AI tier." hint). Current selection derived from `mode` + `plan`. `billing.js` store gains `selectAiTier(slug)` action that POSTs and deep-merges the response.

**Closes #137 regression: recipe imports now count toward the daily cap.** [RecipeImportService](app/Services/RecipeImportService.php) makes two Anthropic calls (`extractViaLlm` for HTML and `extractFromPhoto` for vision) that previously bypassed `AiUsageService::recordMessage()`. New `trackUsage()` helper reads `usage.input_tokens` / `usage.output_tokens` from each response and records — skipping when `! $usage->shouldEnforce($family)` to mirror ChatController's BYOK/self-hosted handling.

**Tests** (10 new, all green; full suite remains green):

- `AiTierTest`: 403 when billing disabled · 403 for non-owner · 422 for invalid tier slug · 422 for no base subscription · 200 success delegates to service and returns summary · `summary` includes `ai_tier` block · daily cap resolves from `chatbot.plan` slug (regression for `AiUsageService::limitFor`).
- `RecipeImportUsageTest`: photo import records usage with token counts · URL import records usage on LLM fallback (JSON-LD path correctly skips since no Anthropic call) · BYOK family does NOT record (regression on `shouldEnforce` gating).

**Manual Stripe-side step (one-time, before flipping `BILLING_ENABLED=true`).** For each managed tier, create a recurring Price on a single "Kinhold AI" product:

1. AI Lite — $5/mo recurring
2. AI Standard — $15/mo recurring
3. AI Pro — $30/mo recurring

Then set `STRIPE_PRICE_AI_LITE`, `STRIPE_PRICE_AI_STANDARD`, `STRIPE_PRICE_AI_PRO` in `.env`. The implementation is config-aware and the picker grays out unconfigured tiers cleanly.

**Out of scope (per #217):** BYOK key entry UI → 70-E. Webhook sync of cancellations back to `chatbot.plan` → 70-H.

**Files**

- New: `tests/Feature/Billing/AiTierTest.php`, `tests/Feature/Services/RecipeImportUsageTest.php`
- Modified: `app/Services/BillingService.php` (selectAiTier, currentAiPriceId, buildSwapPriceList, configuredAiPriceIds, aiTierSummary, AiUsageService dependency), `app/Http/Controllers/Api/V1/BillingController.php` (selectAiTier action), `app/Services/RecipeImportService.php` (AiUsageService dependency, trackUsage helper called after both Anthropic responses), `routes/api.php` (POST /billing/ai-tier), `resources/js/components/billing/BillingPanel.vue` (tier picker card), `resources/js/stores/billing.js` (DEFAULT_AI_TIER, selectAiTier action), `config/version.php` (1.8.6)

## 2026-05-02 — Storage metering: nightly tally + soft overage UI (v1.8.5, [#216](https://github.com/gregqualls/kinhold/issues/216) / [#70](https://github.com/gregqualls/kinhold/issues/70)-C)

Third slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. Teaches the system to **measure how much storage each family is using and bill transparently for anything over the included 5 GB**. Still hidden behind `BILLING_ENABLED=false` — no production impact.

**Product model: soft + metered, no upload blocking.** Families go past 5 GB freely; the nightly tally pushes overage to Stripe at $1/GB·month and the BillingPanel surfaces a usage bar + an inline "+N GB at $1/GB·month" callout when over. Hard caps were considered and explicitly rejected for v1 (per [#216](https://github.com/gregqualls/kinhold/issues/216)) — they create support tickets and surprise users. Can be revisited if abuse appears.

**Denormalized current-totals table** ([`family_storage_usages`](database/migrations/2026_05_02_000000_create_family_storage_usages_table.php)). One row per family — mirrors the [`AiUsageDaily`](app/Models/AiUsageDaily.php) shape but per-family rather than per-day, since the BillingPanel only needs the current total. Columns:

- `total_bytes` — fresh sum of all the family's `documents.size`
- `reported_bytes` — last absolute total we successfully pushed to Stripe. Lets us push **deltas only** (Stripe meter events are additive under `sum` aggregation; absolute pushes would double-count nightly)
- `last_calculated_at`, `last_reported_at`

**[`StorageMeteringService`](app/Services/StorageMeteringService.php).** Single source of truth — the artisan command, the Document model events, and `BillingService::summary()` all read through it. Public API:

- `recalcFamily(Family)` — sums `documents.size` for one family by joining through every registered polymorphic owner that ladders up to a `family_id`. Today: `VaultEntry → vault_entries.family_id`. The `OWNERS` registry is a one-line addition when Tasks/Recipes/etc. gain `morphMany(Document)`.
- `reportToStripe(Family)` — recalcs, then pushes the unreported overage delta as a meter event via Cashier 16's `SubscriptionItem::reportUsage()` (uses Stripe v2 Meter Events under the hood). Lazy-adds the metered price via `Subscription::addMeteredPrice()` if missing on a subscribed family — self-healing safety net for any family that subscribed before the storage item was wired in.
- `tallyAll()` — iterates every family, returns `['recalculated' => N, 'reported' => M]`.
- `summaryFor(Family)` — payload for `BillingService::summary()`.

Skipped (no Stripe call) when: `BILLING_ENABLED=false`; the family has no active subscription; `STRIPE_PRICE_STORAGE_OVERAGE` is unset; new overage ≤ already-reported overage.

**Real-time freshness.** [`Document::booted()`](app/Models/Document.php) hooks `created` + `deleted` to call `StorageMeteringService::onDocumentChange()`, which resolves the family via the polymorphic registry and recalcs synchronously. Cheap (one aggregate per family) and keeps the UI number warm without waiting for the nightly tally. Stripe push stays nightly.

**Reporting unit: GB rounded up, base-1024.** `ceil((total_bytes − 5 × 1024³) / 1024³)`. 5 GiB exactly → 0 overage; 5 GiB + 1 byte → 1 GB; 6 GiB → 1 GB; 6 GiB + 1 byte → 2 GB. Documented in `StorageMeteringTest::test_overage_math_rounds_up_full_gib_base_1024`.

**Artisan + schedule.** `kinhold:tally-storage` runs nightly at `02:00` ([`routes/console.php`](routes/console.php)). Idempotent — pushes deltas only, so re-running mid-day is a no-op for the second run. Safe to invoke ad-hoc for a forced refresh.

**Checkout includes the metered price upfront.** `BillingService::createBaseCheckout()` now calls `$builder->meteredPrice($storagePriceId)` so the first invoice already has the line item we need to push usage to. The lazy-add safety net in the tally covers any family who somehow subscribed before this code shipped.

**SPA: storage bar in `BillingPanel`.** New section under the plan card. Progress bar fills proportional to `used / included`; flips amber at 100%. When over: shows "+N GB at $1/GB·month — adds $X.XX to your next invoice." Hidden until the first tally has populated `last_calculated_at` so brand-new families don't see a misleading "0 of 5 GB" before any Documents exist. The `billing.js` store gains a `summary.storage` block with bytes/overage/timestamps, hydrated from `/api/v1/billing/current`.

**Manual Stripe-side step (one-time, before flipping `BILLING_ENABLED=true`).** The Stripe sandbox "Storage Overage" product currently holds a $1/mo flat placeholder from 70-A's pre-flight. Before this code does anything in Stripe, Greg needs to:

1. Create a Stripe Meter — `event_name=kinhold_storage_gb`, `default_aggregation.formula=sum`, `customer_mapping.event_payload_key=stripe_customer_id`, `customer_mapping.type=by_id`, `value_settings.event_payload_key=value`.
2. On the existing `prod_URGFwoBloTkb6h` ("Kinhold Storage Overage") product, create a metered Price — `unit_amount=100`, `currency=usd`, `recurring.interval=month`, `recurring.usage_type=metered`, `recurring.meter=<meter_id>`.
3. Archive the old `price_1TSNjmABMDyP0kfqZYQrt7z8` flat price.
4. Update `STRIPE_PRICE_STORAGE_OVERAGE` in `.env` with the new price ID.

The Stripe MCP doesn't expose Billing Meter operations, so this is dashboard-only. The implementation is config-aware and no-ops cleanly until step 4.

**Tests** (12 new, 260 total / 677 assertions, all green):

- Tally correctness across multiple VaultEntries; multi-family isolation
- Real-time hook fires on Document created + deleted
- Polymorphic registry rejects unknown `documentable_type` without crashing
- Overage math: ceil-base-1024 across boundary cases
- Summary payload: zero state + populated state
- `reportToStripe()` gates: billing disabled, no subscription, price unconfigured
- `tallyAll()` recalculates without pushing when no subscriptions exist
- Artisan command runs cleanly on empty state

**Files**

- New: `database/migrations/2026_05_02_000000_create_family_storage_usages_table.php`, `app/Models/FamilyStorageUsage.php`, `app/Services/StorageMeteringService.php`, `app/Console/Commands/TallyStorage.php`, `tests/Feature/Billing/StorageMeteringTest.php`
- Modified: `app/Models/Document.php` (`booted()` hooks), `app/Models/Family.php` (`storageUsage()` `HasOne`), `app/Services/BillingService.php` (`summary().storage`, `createBaseCheckout()` adds metered price), `routes/console.php` (nightly schedule), `resources/js/stores/billing.js` (`storage` block in `DEFAULT_SUMMARY`), `resources/js/components/billing/BillingPanel.vue` (storage bar + overage callout), `config/version.php` (1.8.5)

## 2026-05-01 — Billing panel: base plan checkout + lifecycle UI (v1.8.4, [#215](https://github.com/gregqualls/kinhold/issues/215) / [#70](https://github.com/gregqualls/kinhold/issues/70)-B)

Second slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. Adds the `/api/v1/billing/*` endpoints and the `BillingPanel` Settings section. Still hidden behind `BILLING_ENABLED=false` — no production impact.

**API surface (5 new endpoints, all under `auth` middleware):**

- `GET /api/v1/billing/current` — returns a `BillingSummary` snapshot: plan token, Stripe subscription status, trial/grace-period flags, payment method card brand + last4, trial eligibility, price.
- `POST /api/v1/billing/checkout-session` — creates a Stripe Checkout session and returns the hosted URL. Applies trial days for trial-eligible families (first checkout, `trial_days > 0`).
- `POST /api/v1/billing/portal-session` — returns a Stripe Customer Portal URL for invoice history and payment-method management.
- `POST /api/v1/billing/cancel` — cancels at period end (grace period; service stays active until `ends_at`).
- `POST /api/v1/billing/resume` — resumes a grace-period cancellation.

All endpoints 403 when `BILLING_ENABLED=false` or the caller is not the family's billing owner. Checkout and portal endpoints throttled 10 req/min; cancel/resume throttled 5 req/min.

**`BillingService` extended** with `summary()`, `createBaseCheckout()`, `billingPortalUrl()`, `cancel()`, and `resume()`. PHPStan-safe: uses `$checkout->asStripeCheckoutSession()->url` and `$paymentMethod->asStripePaymentMethod()->card` to avoid `__get` magic-property inference errors.

**Trial-aware copy.** Hosted Kinhold has no permanent free tier — `plan: 'none'` replaces the earlier `'free'` token (self-hosted stays `'self_hosted'`). The `BillingPanel` surfaces three distinct states:

- **Trial eligible** (never subscribed, trial days configured) — "Start your free trial" headline, "Try the full Kinhold experience free for N days. After your trial, \$10/mo." description, "Start N-day free trial" CTA.
- **No active subscription** (trial ended / returning family) — "No active subscription" headline, "Your trial has ended. Subscribe to continue using Kinhold." description, "Subscribe — \$10/mo" CTA.
- **Subscribed** — plan/status badge, payment-method line, "Manage payment & invoices" portal button, "Cancel subscription" ghost button.
- **Grace period (cancelled, not yet lapsed)** — "Cancelling" badge, lifecycle date, "Resume subscription" primary button.

**Settings integration.** The `Billing & subscription` section appears only when `billing_enabled && family.billing_owner_id === currentUser.id` — non-owner parents and children never see it. `billing_owner_id` is now serialized in `AuthController::user()` and `FamilyResource`.

**Tests** (13 new, 248 total / all green):

- `BillingControllerTest` — owner gets correct summary JSON; 403 for billing-disabled / non-owner / child / unauthenticated; checkout validates URLs and returns mocked Stripe URL; portal 422 without Stripe ID; cancel 422 without subscription; resume 422 without grace period.
- `BillingFoundationTest` updated: `'free'` → `'none'` token.

**Filed alongside this PR:** [#223](https://github.com/gregqualls/kinhold/issues/223) (70-I — subscription paywall/splash after trial expiry) and [#224](https://github.com/gregqualls/kinhold/issues/224) (replace `window.confirm` cancel dialog with `BaseModal`).

**Files:**

- New: `app/Http/Controllers/Api/V1/BillingController.php`, `resources/js/components/billing/BillingPanel.vue`, `resources/js/stores/billing.js`, `tests/Feature/Billing/BillingControllerTest.php`
- Modified: `app/Services/BillingService.php` (extended), `app/Http/Controllers/Api/V1/AuthController.php` (`billing_owner_id` in family payload), `app/Http/Resources/FamilyResource.php` (`@mixin Family` + `billing_owner_id`), `resources/js/views/settings/SettingsView.vue` (billing section + `canSeeBilling`), `routes/api.php` (billing routes), `config/version.php` (1.8.4), `phpstan-baseline.neon` (removed 4 stale FamilyResource entries)

## 2026-05-01 — Billing foundation: Cashier + `BILLING_ENABLED` gate (v1.8.3, [#214](https://github.com/gregqualls/kinhold/issues/214) / [#70](https://github.com/gregqualls/kinhold/issues/70)-A)

First slice of the [#70](https://github.com/gregqualls/kinhold/issues/70) Stripe billing umbrella. Lands the plumbing — no user-visible features. Every subsequent billing PR (70-B…70-H) is invisible-by-default until launch under `BILLING_ENABLED=false`. Public flip happens at v1.9.0.

**Cashier on Family, not User.** Per the [#70](https://github.com/gregqualls/kinhold/issues/70) decision (2026-05-01), Stripe customers are scoped to `families` rather than individual users — one billing owner per family. The wrinkle: Cashier defaults to a User billable with auto-incrementing IDs, but `Family` is UUID-keyed (`HasUuids`). Three adjustments make this work end-to-end:

1. **`Cashier::useCustomerModel(Family::class)`** in [`AppServiceProvider::boot()`](app/Providers/AppServiceProvider.php) — points the trait at the Family model.
2. **Consolidated migrations.** The vendor-published Cashier migrations target `users` with `foreignId('user_id')`. Replaced them with four Kinhold-flavored migrations dated `2026_05_01_*` that target `families` and use `uuid('family_id')` with a real FK to `families.id`. Cashier's `subscriptions()` relation reads `$this->getForeignKey()` against the Billable model — putting `Billable` on `Family` makes Eloquent return `family_id` automatically, no override needed.
3. **`add_billing_owner_id_to_families_table`** — `billing_owner_id` UUID nullable + FK to `users.id` (`nullOnDelete`), index, and a PHP-level backfill (cross-DB safe — runs on PostgreSQL hosted and SQLite self-hosted/CI) that picks the oldest parent in each family.

**Pre-flight closed in this PR.** Stripe sandbox `acct_1TSN2SABMDyP0kfq` had only the base plan ("Household Hosting", $10/mo) when this work started. Created the four missing products via the Stripe MCP during execution: Storage Overage ($1/mo placeholder — reconfigured to metered usage in 70-C), AI Lite ($5/mo), AI Standard ($15/mo), AI Pro ($30/mo). Resulting price IDs are documented in the PR body for Greg to paste into `.env`. The AI tier IDs reuse the `STRIPE_PRICE_AI_*` env slots that have been reserved in `config/kinhold.php` since [#137](https://github.com/gregqualls/kinhold/issues/137); 70-D wires them.

**`BILLING_ENABLED` gate.** Mirrors the `SELF_HOSTED` pattern from [#138](https://github.com/gregqualls/kinhold/issues/138) 1:1 — defined in `config/kinhold.php`, exposed via `/api/v1/config` (the same inline-closure endpoint that already exposes `self_hosted` and `license`), defaults `false`. The `BillingService` skeleton lives at [`app/Services/BillingService.php`](app/Services/BillingService.php) with two methods: `isEnabled()` and `resolveCurrentPlan(Family $family)` returning `'self_hosted' | 'free' | 'base'`. No Stripe calls yet — that surface area opens up in 70-B.

**Tests** (7 new, 235 total / 608 assertions, all green):

- `BillingFoundationTest` — `/api/v1/config` exposes `billing_enabled` correctly in both states; `BillingService::isEnabled()` and `resolveCurrentPlan()` return the right tokens; the migration's parent-picking backfill algorithm is correct; the `Billable` trait wires up against a UUID-keyed `Family` (`hasStripeId()` and `subscriptions` relation work without throwing).

**Files**

- New deps: `laravel/cashier ^16.5`, `stripe/stripe-php ^17.6`, `moneyphp/money ^4.8`
- New: `database/migrations/2026_05_01_000000_add_billing_owner_id_to_families_table.php`, `database/migrations/2026_05_01_000001_add_cashier_columns_to_families_table.php`, `database/migrations/2026_05_01_000002_create_subscriptions_table.php`, `database/migrations/2026_05_01_000003_create_subscription_items_table.php`, `app/Services/BillingService.php`, `tests/Feature/Billing/BillingFoundationTest.php`, `config/cashier.php` (vendor-published)
- Modified: `app/Models/Family.php` (Billable trait, `billing_owner_id` fillable), `app/Providers/AppServiceProvider.php` (Cashier customer model binding), `config/kinhold.php` (`billing_enabled` + `billing.*` block), `config/services.php` (Stripe credentials block), `config/version.php` (1.8.3), `routes/api.php` (one new line in `/api/v1/config` payload), `.env.example` (Stripe + billing entries)

**Out of scope (deferred to later 70-X PRs):** any UI, webhook handlers, storage metering, AI tier purchase wiring, BYOK key UX, public landing page, onboarding plan picker, lifecycle emails. PR #197 (Google OAuth verification prep, dormant since 2026-04-28) was closed here too — Greg will revisit with a different approach.

## 2026-04-30 — Hotfix (v1.8.2): Google OAuth callback intercepted by PWA service worker

After PR #68 (PWA support) shipped, Google sign-in started silently breaking for any user who already had the service worker registered. Symptom: after Google consent, the browser landed on `/auth/google/callback?code=…` with the SPA shell rendering a 404 in the inner content area, and DevTools showing `POST /api/v1/auth/exchange → 422`.

**Root cause** — the SW's `navigateFallbackDenylist` in [vite.config.js](vite.config.js) covered `/api/`, `/mcp`, `/login`, `/oauth`, `/sanctum`, and `/storage/`, but **not `/auth/`**. So Google's redirect to `/auth/google/callback?code=…` got intercepted by the SW's `NavigationRoute`, which served the cached `/` SPA shell instead of letting the request reach Laravel's `GoogleAuthController::callback`. The SPA's bootstrap code (`auth.js:253-260`) then read Google's raw authorization code (~70 chars, contains `/`) from the URL and POSTed it to `/api/v1/auth/exchange`, which validates `'code' => 'required|string|size:64'` (a 64-char Laravel-issued token) — hence the 422.

**Fix** — one new entry, `/^\/auth\//`, in `navigateFallbackDenylist`. Broad enough to cover all current and future auth paths in [routes/web.php](routes/web.php) (`/auth/google/redirect`, `/auth/google/callback`, `/auth/google/link-callback`, `/auth/oauth-login`, `/auth/google/oauth-callback`) without needing a SW redeploy each time a new auth route is added.

`vite-plugin-pwa` is configured with `registerType: 'autoUpdate'`, so already-registered clients pick up the new SW on their next non-`/auth/` page load. Users currently stranded on `/auth/google/callback` may need to navigate to `/` and hard-reload (or unregister the SW from DevTools).

**Files**

- Modified: `vite.config.js` (one new regex in `navigateFallbackDenylist`)

## 2026-04-30 — Web push notifications: reminders + activity types ([#69](https://github.com/gregqualls/kinhold/issues/69), part 2 of 2)

Second half of [#69](https://github.com/gregqualls/kinhold/issues/69) — closes the issue by wiring four scheduled / activity notifications on top of the foundation shipped in part 1. Also ships three #69a follow-ups (N+1 fix, GDPR regression test, a11y) since they touch the same surface.

**Four new notifications:**

- **`TaskDueSoonNotification`** (`task_due_soon`) — Fires once per task the morning it's due (8am). New nullable `due_reminder_sent_at` timestamp column on `tasks` + composite index on `(due_date, due_reminder_sent_at)` provides idempotency — re-running the cron never double-fires. Backfill in the migration marks all overdue incomplete tasks as already-reminded so the first deploy tick doesn't spam. Supports both email and push; defaults push-on (the headline value-add of #69b). Schedule: `dailyAt('08:00')` rather than `*/30` — `due_date` is a `date` column, so there's no datetime precision a 30-minute cron can take advantage of; one "good morning" is the right UX.

- **`ShoppingListItemAddedNotification`** (`shopping_item_added`) — Fires from `ShoppingListService::addItem()` when a member manually adds an item; excluded from `addRecipeIngredients()` (a meal-plan cascade adding 12 items at once would push 12 notifications). Actor (adder) excluded from recipients. Push-only, defaults off. Tag `shopping-list-{list_id}` means three rapid adds collapse to one notification in the OS tray — the right UX for a busy shopping session.

- **`CalendarEventReminderNotification`** (`calendar_event_reminder`) — Fires per-user before a family event. New nullable `reminder_minutes_before` column on `family_events` (null = reminders disabled; default null so existing events don't suddenly fire). New `event_reminder_sends` dedup table with `UNIQUE(family_event_id, occurrence_date)` — needed because a recurring event has many occurrences; a column on `family_events` would block the next instance after the first reminder fires. The cron (`everyFiveMinutes`) uses a 60-min lookahead window to stay robust against queue stall or cron drift. Supports email + push, defaults push-on.

- **`DinnerReminderNotification`** (`dinner_reminder`) — Fires at the user's configured `dinner_reminder_at` time (stored in `notification_preferences`, already on the model from part 1). SQL JSON-key pre-filter on the `notification_preferences` column collapses all users down to the handful whose reminder time matches server-time right now; per-user TZ re-check inside the loop eliminates false positives. Push-only, defaults off. Reads today's dinner `MealPlanEntry`; body says "You're cooking" if the user appears in `assigned_cooks`, else "On the menu".

**Three #69a follow-ups:**

- **N+1 fix** — `User::wants('push', ...)` now routes through `getCachedPushSubscriptionCount()`, which honors `pushSubscriptions` eager-loaded via `with(...)` or `withCount(...)` before falling back to a fresh `COUNT(*)`. Every new command eager-loads the relation inside `chunkById` — zero subscription queries inside any dispatch loop. Instance-level memoization was considered but dropped: calling `refresh()` between subscription changes in tests left stale zeros, making the tests misleading.

- **GDPR regression test** — New assertion in `UserDataExportTest` confirms `notification_preferences` (including `dinner_reminder_at`) is present in the exported `user.json`. No code change was needed — the key was already exported — but the test locks that behavior.

- **a11y: disabled checkbox styling** — Both `<input type="checkbox">` elements in `NotificationsPanel.vue` now carry `disabled:opacity-40 disabled:cursor-not-allowed` so users see visual feedback when a channel toggle is unavailable (e.g., push unavailable until permission is granted).

**Schedule registration** (`routes/console.php`):
- `app:send-task-due-reminders` → `dailyAt('08:00')`
- `app:send-event-reminders` → `everyFiveMinutes()`
- `app:send-dinner-reminders` → `everyMinute()`

**Tests** (27 new, 224 total / 588 assertions, all green):

- [TaskDueSoonNotificationTest](tests/Feature/Notifications/TaskDueSoonNotificationTest.php) — both channels, strip push if no subscription, strip push during quiet hours, empty `via()` if opted out (4)
- [ShoppingListItemAddedNotificationTest](tests/Feature/Notifications/ShoppingListItemAddedNotificationTest.php) — notifies other family members, skips adder, `addRecipeIngredients` produces no notifications (regression), `via()` is push-only (4)
- [CalendarEventReminderNotificationTest](tests/Feature/Notifications/CalendarEventReminderNotificationTest.php) — both channels when opted in, skips opted-out, strips push when globally muted (3)
- [DinnerReminderNotificationTest](tests/Feature/Notifications/DinnerReminderNotificationTest.php) — returns push when subscribed, empty when opted out, `via()` is push-only (3)
- [SendTaskDueRemindersTest](tests/Feature/Console/SendTaskDueRemindersTest.php) — sends for due-today + assigned, skips already-reminded, skips completed / unassigned (3)
- [SendCalendarEventRemindersTest](tests/Feature/Console/SendCalendarEventRemindersTest.php) — fires one-time event in window, fires the right weekly occurrence, unique constraint prevents double-fire, skips null `reminder_minutes_before` (4)
- [SendDinnerRemindersTest](tests/Feature/Console/SendDinnerRemindersTest.php) — dispatches at user's local `dinner_reminder_at`, skips wrong local time, skips no-dinner-planned (3)
- [PushSubscriptionCountCachingTest](tests/Unit/User/PushSubscriptionCountCachingTest.php) — eager-loaded relation avoids subscription query, `withCount` avoids subscription query (2)
- [UserDataExportTest](tests/Feature/UserDataExportTest.php) — extended: `notification_preferences` present in `user.json` (1)

**PHPStan:** 9 new baseline entries for Larastan cast-inference limitations on `start_time->hour/minute/second`, `due_date->format()`, `assigned_cooks` array checks, `entry->date->toDateString()` — same pattern as the pre-existing `TaskAssignedNotification` entry.

**Files**

- New: `database/migrations/2026_04_30_170000_add_due_reminder_sent_at_to_tasks_table.php`, `database/migrations/2026_04_30_170100_add_reminder_minutes_before_to_family_events_table.php`, `database/migrations/2026_04_30_170200_create_event_reminder_sends_table.php`, `app/Models/EventReminderSend.php`, `app/Notifications/TaskDueSoonNotification.php`, `app/Notifications/ShoppingListItemAddedNotification.php`, `app/Notifications/CalendarEventReminderNotification.php`, `app/Notifications/DinnerReminderNotification.php`, `app/Console/Commands/SendTaskDueReminders.php`, `app/Console/Commands/SendCalendarEventReminders.php`, `app/Console/Commands/SendDinnerReminders.php`, 8 test files
- Modified: `config/notifications.php` (4 new registry entries), `app/Models/User.php` (N+1 fix), `app/Models/Task.php` (`due_reminder_sent_at` fillable + cast), `app/Models/FamilyEvent.php` (`reminder_minutes_before` fillable + cast), `app/Services/ShoppingListService.php` (dispatch in `addItem()`), `routes/console.php` (3 new schedule entries), `resources/js/components/notifications/NotificationsPanel.vue` (disabled-checkbox a11y classes), `phpstan-baseline.neon` (9 new entries)

## 2026-04-30 — Web push foundation ([#69](https://github.com/gregqualls/kinhold/issues/69), part 1 of 2)

First half of [#69](https://github.com/gregqualls/kinhold/issues/69) — push subscription infrastructure plus a registry framework so adding a new notification type later is just "drop a class + add a config entry," not a five-file edit. Two existing notification surfaces (task assigned, kudos received) now fire push alongside email, gated by per-user prefs + quiet hours + global mute. Reminders and activity-style notifications (task due soon, shopping list activity, calendar event reminders, dinner reminder) follow in #69b against the stable channel.

**Service worker strategy.** Kept #68's `generateSW` workbox setup intact and added the `push` + `notificationclick` handlers via workbox's [`importScripts`](vite.config.js) option, which prepends `self.importScripts('/sw-push.js')` at the top of the generated SW. The custom file at [public/sw-push.js](public/sw-push.js) owns ~50 lines of native `Notification` rendering + tab-focus logic — workbox's precaching, `navigateFallback`, and runtime caching code paths are untouched. The alternative (switching to `injectManifest`) would have forced a full rewrite of #68's caching strategy for two event handlers; skipped.

**Notification type registry.** New [config/notifications.php](config/notifications.php) defines categories + types in one place. Each type entry declares its category, label, supported channels (`['email']`, `['email','push']`, etc.), per-channel defaults for new users, and an optional `requires_module` gate so types are hidden when the family disables their parent module. The Settings UI iterates the registry — a new notification type appears in the right category automatically with no UI changes. The legacy four-key `email_preferences` shape is kept readable for one release for safe rollback.

**User preferences shape.** New `notification_preferences` JSONB column on `users` with the shape:

```json
{
  "email": { "task_assigned": true, ... },
  "push":  { "task_assigned": true, ... },
  "quiet_hours": { "enabled": false, "start": "22:00", "end": "07:00" },
  "muted": false,
  "dinner_reminder_at": "15:00"
}
```

Migration backfills `notification_preferences->email` from the legacy `email_preferences` column on the same deploy. New `User::wants($channel, $key)` consults notification_preferences first, falls back to legacy `email_preferences`, and finally to the registry's `default_<channel>`. The deprecated `wantsEmail()` helper is now a thin wrapper that strips the `email_` prefix and delegates — keeps existing notification classes (`WeeklyDigestNotification`, `TaskCompletedNotification`, `FamilyInviteNotification`) working unchanged.

**Quiet hours + global mute.** `User::isInQuietHours()` evaluates the window in the user's `timezone` (already stored on the model since #96), correctly handles overnight ranges (`22:00 → 07:00`), and is queried by every notification's `via()`. Global mute strips `webpush` from the channel array; email continues to deliver. Both gates live in `User::isPushSuppressed()` so notification classes don't have to re-check.

**Push permission UX.** New [resources/js/components/NotificationsPrompt.vue](resources/js/components/NotificationsPrompt.vue) mirrors the [#68 InstallAppPrompt](resources/js/components/InstallAppPrompt.vue) pattern — `localStorage` 30-day cooldown, dismissible, mounted in `App.vue` immediately after the install banner. Shown only when `Notification.permission === 'default'`, the browser supports `PushManager`, the user is authenticated, and VAPID is configured server-side (read from a `<meta name="vapid-public-key">` tag injected by [app.blade.php](resources/views/app.blade.php) at build time). Click → `Notification.requestPermission()` → `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })` → POST to `/api/v1/push/subscriptions`. Never auto-subscribes — permission is a deliberate user action.

**Subscription endpoints.** [PushSubscriptionController](app/Http/Controllers/Api/V1/PushSubscriptionController.php) exposes `POST/DELETE /api/v1/push/subscriptions` (upsert / remove by endpoint) and `POST /api/v1/push/subscriptions/test` (dispatches a sample push to all of the caller's subscriptions — the in-Settings "Send test" button). The package's `HasPushSubscriptions` trait handles the cross-device case: re-registering an endpoint that already belongs to another user reassigns it to the caller (covers shared-device handoff between family members).

**Settings UI.** New [NotificationsPanel.vue](resources/js/components/notifications/NotificationsPanel.vue) replaces the previous email-only Notifications block. Layout: permission row (Enable / Send test / Disable) → global mute switch → quiet-hours toggle with `<input type="time">` start/end → per-category groups (registry-driven, collapsible) → each row shows the type's label + an Email × Push checkbox grid (channel toggles only render for channels the type actually supports). Same component renders for both parent and child versions of [SettingsView.vue](resources/js/views/settings/SettingsView.vue) so kids get the same controls.

**VAPID configuration.** `php artisan webpush:vapid` (provided by `laravel-notification-channels/webpush`) generates the keypair. Three new env vars (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`) added to `.env.example` with a generation walkthrough; [SELF-HOSTING.md](SELF-HOSTING.md) gains a "Web Push Notifications" section under Configuration → Optional Services. The public key is exposed to the SPA via the Blade meta tag — no `/api/v1/config` round-trip — since it's a build-time constant per deploy.

**Migration shape.** [push_subscriptions](database/migrations/2026_04_30_160000_create_push_subscriptions_table.php) keeps the upstream package's `bigIncrements` PK (internal-only ID, doesn't cross model boundaries) but switches the polymorphic FK to `uuidMorphs('subscribable')` so it can reference our UUID-keyed `users` table cleanly. The package's published migration was deleted and replaced rather than edited so the rename of the timestamp prefix matches our other migrations.

**Tests** (22 new, 197 total / 542 assertions, all green):

- [PushSubscriptionTest](tests/Feature/PushSubscriptionTest.php) — auth gating, upsert by endpoint, cross-user device handoff, destroy, test endpoint requires existing subscription
- [NotificationPreferencesTest](tests/Feature/NotificationPreferencesTest.php) — defaults shape, persistence, registry-key filtering (unknown keys silently dropped), validation
- [UserNotificationPreferencesTest](tests/Unit/UserNotificationPreferencesTest.php) — registry-default fallback, no-email short-circuit, push-requires-subscription, quiet hours same-day + overnight + cross-timezone, global mute
- [KudosReceivedNotificationTest](tests/Feature/Notifications/KudosReceivedNotificationTest.php) — fires from `PointsService::giveKudos()`, skips self-kudos, push channel only when subscribed + opted in, global mute strips push

One Laravel testing gotcha worth recording: `NotificationFake::sendNow` silently drops a notification when its `via()` returns `[]`. The tests for "fires when subscribed" must give the notifiable at least one enabled channel; otherwise `assertSentTo` reports "the notification was not sent" with no further hint. Test setup explicitly enables the relevant pref before dispatching.

**Files**

- New: `composer.json` / `composer.lock` (`laravel-notification-channels/webpush ^10.5`), `config/webpush.php`, `config/notifications.php`, `database/migrations/2026_04_30_160000_create_push_subscriptions_table.php`, `database/migrations/2026_04_30_160100_add_notification_preferences_to_users_table.php`, `app/Notifications/KudosReceivedNotification.php`, `app/Notifications/TestPushNotification.php`, `app/Http/Controllers/Api/V1/PushSubscriptionController.php`, `public/sw-push.js`, `resources/js/services/push.js`, `resources/js/stores/notifications.js`, `resources/js/components/NotificationsPrompt.vue`, `resources/js/components/notifications/NotificationsPanel.vue`, four test files
- Modified: `app/Models/User.php` (HasPushSubscriptions trait, `wants()`, `isInQuietHours()`, `isPushMuted()`, `isPushSuppressed()`, `notification_preferences` cast + fillable, `defaultNotificationPreferences()`), `app/Notifications/TaskAssignedNotification.php` (push channel), `app/Services/PointsService.php` (kudos dispatch), `app/Http/Controllers/Api/V1/SettingsController.php` (notification-preferences endpoints + filtered registry helper), `routes/api.php`, `vite.config.js` (`importScripts`), `resources/views/app.blade.php` (VAPID meta tag), `resources/js/App.vue` (mount NotificationsPrompt), `resources/js/views/settings/SettingsView.vue` (replace email block with NotificationsPanel), `.env.example`, `SELF-HOSTING.md`

**What's still pending in #69:** task-due-soon scheduler + dedup column, shopping-list-item-added activity notification, calendar event per-event reminder column + scheduler, dinner reminder (per-user time + meal-plan-aware scheduler). All additive against this foundation — see plan file for the #69b file list.

## 2026-04-30 — Phase B kickoff: PWA support + mobile UX polish ([#68](https://github.com/gregqualls/kinhold/issues/68))

Opens **Phase B: Make It Reachable** by closing [#68](https://github.com/gregqualls/kinhold/issues/68) (PWA: service worker, manifest, installable) and bundling four mobile UX issues Greg flagged from daily-driving the app on his phone. All five are mobile-surface changes touching the same SPA shell — splitting them would have produced four trivial follow-up PRs for a reviewer who's already looking at the install banner.

**PWA infrastructure.** Adds `vite-plugin-pwa` (workbox `generateSW` strategy) wired into [vite.config.js](vite.config.js). The plugin emits `sw.js` + `workbox-*.js` to `public/build/` (laravel-vite-plugin's hashed-asset directory), which would give the SW a `/build/` scope — too narrow to control the SPA. A small `promoteServiceWorkerToRoot()` Vite plugin defined in the same file copies both files to `public/` after build so the SW lands at `/sw.js` with full `/` scope. The companion fix for the precache URLs uses `modifyURLPrefix: { 'assets/': '/build/assets/' }` so workbox-generated cache entries resolve through Laravel's hashed-asset path. Precache scope is deliberately tight — `globPatterns: ['assets/app-*.{js,css}']` covers only the entry chunk + global CSS (3 entries, ~790 KiB) instead of every code-split route chunk (would have been 208 entries, 2.7 MiB); route chunks get cached at runtime on first visit via the `CacheFirst` destination-based handler. API requests use `NetworkFirst` with a 4-second timeout so offline degrades gracefully without crippling latency when online.

**Manifest + iOS hints.** [public/manifest.json](public/manifest.json) gets `scope`, `orientation`, `categories`, a maskable-purpose icon entry (Android adaptive icons), and a `shortcuts` array (Tasks/Calendar/Vault/Points) for app-icon long-press. [resources/views/app.blade.php](resources/views/app.blade.php) gains the iOS PWA quartet: `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style: black-translucent`, `apple-mobile-web-app-title`, plus the deprecated `mobile-web-app-capable` alias for older Android.

**Install prompt.** New [resources/js/components/InstallAppPrompt.vue](resources/js/components/InstallAppPrompt.vue) listens for `beforeinstallprompt` (Android/Chrome) and renders a brand-gold dismissible banner styled after [LicenseWarningBanner](resources/js/components/LicenseWarningBanner.vue). For iOS Safari (which doesn't fire that event), it detects `iPad|iPhone|iPod` UA + Safari (excluding `CriOS`/`FxiOS`/`EdgiOS` wrappers) and shows the share-icon hint instead. Hides itself when already in standalone mode (`display-mode: standalone` or `navigator.standalone`) and when recently dismissed (30-day cool-down via `localStorage`). Mounts in [App.vue](resources/js/App.vue) right after `<LicenseWarningBanner />`.

**iOS input auto-zoom fix.** Added a global mobile-only CSS rule to [resources/css/app.css](resources/css/app.css):
```css
@media (max-width: 767px) {
  input:not([type="checkbox"])..., textarea, select {
    font-size: max(16px, 1em) !important;
  }
}
```
The `!important` is necessary — Tailwind's `text-[15px]` / `text-sm` utility classes win on specificity otherwise, and iOS auto-zoom is non-negotiable UX. Also hardens [KinInput.vue](resources/js/components/design-system/KinInput.vue) at the source: sm/md sizes now use `text-[16px] md:text-[<smaller>]` so mobile gets 16px and desktop keeps its compact density. ChatView's textarea explicitly bumps from `text-sm` to `text-base md:text-sm` for the same reason.

**Chat view spacing.** The chat input bar sat 24px above the floating bottom nav — visually disconnected because [App.vue](resources/js/App.vue)'s `pb-24` (96px) over-reserves clearance for views where the input *is* the bottom UI. Added `-mb-4 md:mb-0` to ChatView's input container so on mobile it pulls down into that pad-zone, leaving an 8px gap to the nav. Desktop reverts to `mb-0` + `pb-safe-bottom`. The messages container also gets `pt-4 pb-2` (was `py-4`) so the last message has tight breathing room above the input strip.

**Quick-give kudos.** [PointsFeedView](resources/js/views/points/PointsFeedView.vue) previously parked `<KudosInput />` at the bottom of the activity-feed card — meaning every kudos required scrolling past the entire feed first. Moved it to a dedicated `<KinFlatCard>` above the hero balance, where it's reachable in one tap from the page top. Same component, same `handleKudos` handler — pure layout change. The bottom strip is removed; the activity feed card collapses to its pre-strip footer.

**Meal-plan list view default on desktop.** [MealsTab](resources/js/views/food/MealsTab.vue) had a single desktop view: the dense transposed `MealWeekGrid` (slots as rows, days as columns) with each cell at MIN_DAY_COL_PX = 140px — cramped on anything narrower than 1280px. Added a viewMode toggle mirroring the [RecipesTab](resources/js/views/food/RecipesTab.vue) pattern (`'grid' | 'list'`, persisted to `localStorage` under `kinhold-meal-view`). List mode reuses the existing mobile `MealDaySection` component on desktop — same day-stacked rows the mobile view already showed. **Default is `'list'`** so the dense grid is opt-in. Mobile is unchanged (still uses MealDaySection with infinite scroll).

**QA round 2 — meal entry as a row + chat-bar bg continuation.** Two follow-ups after Greg saw the result on his phone:

- **Meal entries on mobile felt card-heavy.** Each entry inside `MealDaySection` was rendering as the same image-on-top card used in `MealWeekGrid` — full gradient hero + tiny title below. Fine for a 140px grid cell; cramped on a 343px-wide phone row where you want to scan the day at a glance. Added a `compact` prop to [MealEntryCard.vue](resources/js/components/meals/MealEntryCard.vue) that renders a row layout instead: 44px thumbnail (image OR per-type gradient wash matching the recipe/restaurant compact-row palette so a recipe-type entry looks visually consistent with how the recipe appears in `RecipesTab`'s compact view) + title + servings + cook avatars + an always-visible delete button (hover-reveal is hostile on touch). `MealDaySection` now passes `compact` to every entry. Card mode is preserved for `MealWeekGrid` (no-prop default).
- **"+ Add" button was a 28px-tall ghost link.** Bumped to 46px tall (`py-3` + `text-sm`) with a dashed border so it reads as a tappable affordance, not a footnote — clears iOS HIG's 44pt touch-target minimum.
- **Chat input bar's white background didn't reach the screen edge.** The input row sat 8px above the floating bottom nav, but the area between/below the nav was app-background (not white) — the input bar looked detached from the bottom of the viewport. Added a `chat-input-extend` class in `app.css` that paints a 96px box-shadow downward in `var(--surface-raised)`, filling the entire bottom strip behind the floating nav. Zero layout impact (no reflow on the messages flex-1 area), dark-mode-aware via the CSS variable, mobile-only via the `md:chat-input-extend-none` companion class.
- **Kudos recipient picker was eating half the row.** The `<KinSelect>` recipient dropdown was 128px wide, leaving the kudos-text input clipped to ~"Kud" on a 343px-wide phone. Replaced with a 40px circular avatar button (`UserAvatar` of the chosen recipient, or a dashed `+` placeholder) that opens a bottom-sheet modal — `<Teleport>`-ed to body, full-width on mobile / centered card on `sm:`, with each member as a tappable row. Selecting a member closes the modal, updates the avatar button, enables the reason input (which was disabled with "Pick a recipient first" placeholder), and auto-focuses the input so typing starts immediately. The reason input now has 189px of horizontal room (was ~58px) and "Give Kudos" shrinks to "Give" since the avatar already telegraphs the action's target.

**Review-pass fixes (`/review` blocked nothing, but flagged five warnings — all addressed):**

- **Offline navigation fallback now actually works.** [vite.config.js](vite.config.js)'s `navigateFallback: '/'` resolves the URL against the precache, so the app shell at `/` had to *be* in the precache for offline navigation to return anything but a 404. Added `additionalManifestEntries: [{ url: '/', revision: 'kin-${Date.now()}' }]` so each build invalidates the cached shell (preventing stale HTML pointing at deleted hashed-asset URLs). Precache went from 3 entries → 4.
- **SW response cache no longer survives logout.** The `NetworkFirst` runtime cache on `/api/*` would happily serve a previous user's cached `/api/v1/me` (and similar) on a shared device after they signed out and the next visitor's network call timed out. [stores/auth.js](resources/js/stores/auth.js)'s `logout()` now iterates `caches.keys()` and deletes every cache prefixed `kinhold-` (matching the `cacheName` convention in `vite.config.js`'s runtime caching block). Best-effort, wrapped in `try/catch` since the Cache API can be unavailable in private mode.
- **Kudos modal keyboard a11y.** Added `@keydown.esc` on the dialog to close it, and auto-focus the close button when the modal opens (so screen-reader / keyboard users land *inside* the dialog, not somewhere outside it). The reason-input auto-focus now uses a template `ref` (`reasonInputRef.value.$el.querySelector('input')`) instead of a fragile placeholder-prefix CSS selector — survives copy edits and i18n.
- **MealEntryCard rows are now keyboard-operable.** Both render modes (compact row + dense card) use `<div>` roots — switching to `<button>` would have been illegal HTML because the inner delete + maps-link `<button>`/`<a>` can't be nested inside a `<button>`. Instead, added `role="button"`, `tabindex="0"`, `aria-label="Edit {title}"`, and `@keydown.enter` / `@keydown.space` handlers (with `.prevent` on Space to stop page scroll). Plus `focus:ring-2 focus:ring-[#C4975A]/40` so keyboard users see where they are.
- **Dependencies.** New devDep `vite-plugin-pwa` ships 4 high-severity transitive vulns in `serialize-javascript ≤7.0.4` (RCE / CPU-DoS) via `@rollup/plugin-terser → workbox-build`. All build-time-only (devDependency tree, not runtime). `npm audit fix --force` would downgrade vite-plugin-pwa to 0.19.8 (breaking, loses workbox 7 features). Accepted; will reassess when [vite-pwa-org/vite-plugin-pwa](https://github.com/vite-pwa/vite-plugin-pwa/releases) bumps workbox-build.

**Files**

- `vite.config.js` — `vite-plugin-pwa` config + `promoteServiceWorkerToRoot()` post-build copy plugin
- `package.json`, `package-lock.json` — `vite-plugin-pwa` devDependency
- `public/manifest.json` — scope, orientation, categories, maskable icon, shortcuts
- `resources/views/app.blade.php` — iOS PWA meta tags
- `resources/js/app.js` — explicit `registerSW({ immediate: true })`
- `resources/js/components/InstallAppPrompt.vue` — new
- `resources/js/App.vue` — mount InstallAppPrompt
- `resources/js/components/design-system/KinInput.vue` — 16px mobile floor on sm/md sizes
- `resources/css/app.css` — global 16px mobile floor for native form fields
- `resources/js/views/chat/ChatView.vue` — textarea 16px on mobile, input bar `-mb-4` to tighten nav gap
- `resources/js/views/points/PointsFeedView.vue` — KudosInput moved to top
- `resources/js/views/food/MealsTab.vue` — viewMode toggle + list default
- `resources/js/components/meals/MealEntryCard.vue` — `compact` prop + row layout
- `resources/js/components/meals/MealDaySection.vue` — `compact` prop pass-through, larger Add button
- `resources/js/components/points/KudosInput.vue` — avatar button + bottom-sheet modal (was inline select); Esc-to-close, focus management, ref-based input focus
- `resources/js/stores/auth.js` — `logout()` purges SW response caches
- `.gitignore` — ignore generated `public/sw.js` + `public/workbox-*.js`
- `docs/ROADMAP.md` — #68 marked DONE
- `CHANGELOG.md` — this entry



Closes the last open Medium in **Phase A: Make It Solid**. Kinhold ships under the Elastic License 2.0, whose "no hosted service" clause already forbids running a competing SaaS on top of the OSS code — but the codebase enforced nothing at the instance level, so a self-hoster could quietly onboard arbitrary unrelated families and build a de facto hosted service. This adds soft, deliberately-annoying enforcement to the self-hosted path plus an explicit LICENSE addendum that defines what "a single family" actually means.

**License addendum.** [LICENSE](LICENSE) now ends with a Kinhold Single-Family Addendum that defines a "family" as the group of individuals who share the same family data within an instance — calendars, vault, tasks, meal plans, household resources. Multi-family households who choose to manage themselves as one shared unit are explicitly considered a single family. Operating multiple unrelated families on a single self-hosted instance now requires a separate commercial license.

**Gate strategy: warn + allow, not hard-stop.** Greg explicitly chose this over hard-stop in planning. Three reasons: (1) the existing Google OAuth flow creates a new family for every first-time login, so a hard-stop would brick legitimate spousal signups via OAuth; (2) the LICENSE clause is the actual legal teeth, and code is just a speed bump that ensures *informed* consent; (3) phone-home license checks are hostile to the privacy-first / self-host audience Kinhold is courting. There is an internal `COMMERCIAL_LICENSE_ACKNOWLEDGED` env flag that suppresses the banner once set — but **this flag is intentionally not advertised** in `.env.example`, the SPA, or self-hosting docs. Public messaging on every operator-facing surface is "contact us for a commercial license"; the bypass instructions are handed out privately once a license is issued. Standard OSS enforcement model (GitLab EE, Sentry's BSL, Elastic itself) — code is a speed bump that ensures *informed* consent, the LICENSE is the contract.

**Three layers stacked:**

1. **Sticky banner** — new `LicenseWarningBanner` Vue component renders amber across the top of the SPA whenever `self_hosted=true`, family count > 1, and the env flag is unset. Reads from the existing `auth.appConfig` store; no new endpoint. Designed to be readable but not dismissible per session — annoying-by-design.
2. **Backend log line** — `Family::booted()` now registers a `created` event listener that emits `Log::warning('Self-hosted Kinhold instance created an additional family.', [...])` whenever a self-hosted instance creates an Nth (N≥2) family. Fires regardless of whether the operator has acknowledged — log line is for forensic/audit value, banner is the user-facing nag.
3. **LICENSE addendum** — see above.

**Service shape mirrors `AiUsageService`** (the pattern Greg validated with #137). New `app/Services/LicenseEnforcementService.php` exposes `shouldWarn()`, `familyCount()` (cached per request to avoid repeating the COUNT query), and `acknowledged()`. The `/api/v1/config` endpoint now resolves the service and returns a `license: { warn, family_count, commercial_license_acknowledged }` block alongside the existing `self_hosted` field — frontend reads it from the same auth-store hydration call, no extra round-trip.

**Drive-by fix in `routes/api.php`.** The `/api/v1/config` endpoint was reading `env('SELF_HOSTED', false)` directly while the rest of the codebase had moved to `config('kinhold.self_hosted')` per #137. Routed through config now so tests can override deterministically.

**Banner copy: contact-only, no bypass instructions.** The banner reads "to run more than one family on a single instance, you'll need a commercial license — Contact us to get one." Greg explicitly asked that no operator-facing surface broadcast the env flag — keeps the bypass mechanism functional for licensees without giving every self-hoster a flag to flip away the warning.

**Tests.** Three new test files: `tests/Unit/LicenseEnforcementServiceTest.php` (8 cases — every combination of self_hosted × family_count × acknowledged), `tests/Feature/LicenseConfigEndpointTest.php` (5 cases — confirms the `license` block shape, public visibility, and that the warn flag flips correctly across all four states), `tests/Feature/FamilyCreationLoggingTest.php` (4 cases — confirms the log fires only on Nth family in self-hosted mode, never on first family or non-self-hosted, and includes the acknowledged flag in context). All follow the `config()->set('kinhold.self_hosted', true)` pattern from `AiUsageServiceTest`.

**Docs.** [SELF-HOSTING.md](SELF-HOSTING.md) gets a new "Single-Family Policy" section between Configuration and Upgrading explaining what counts as a family, how the limit is enforced, and how to inquire about a commercial license. The internal `COMMERCIAL_LICENSE_ACKNOWLEDGED` flag is **not** documented in `.env.example` (intentional — see gate strategy note above).

**Out of scope (captured as follow-ups):** the `GoogleAuthController::findOrCreateUser` gap — first-time OAuth always creates a new family rather than joining an existing one via invite code. On a self-hosted instance, a spouse signing in via Google ends up in their own family. Worth fixing as a separate issue, but not blocking #138 since we're warning-not-blocking. Also: per kickoff, [#99](https://github.com/gregqualls/kinhold/issues/99) (Kinhold icon in Claude Desktop MCP connector) self-resolved — Anthropic likely populated it for verified servers — should be verified and closed alongside this PR to put a bow on Phase A.

**Files**

- `LICENSE` — appended Kinhold Single-Family Addendum
- `config/kinhold.php` — new `commercial_license_acknowledged` key
- `.env.example` — documented new env var
- `app/Services/LicenseEnforcementService.php` — new
- `app/Models/Family.php` — `booted()` event listener
- `routes/api.php` — `license` block in `/api/v1/config`, plus `env()` → `config()` fix
- `resources/js/components/LicenseWarningBanner.vue` — new
- `resources/js/App.vue` — mounts the banner
- `tests/Unit/LicenseEnforcementServiceTest.php` — new
- `tests/Feature/LicenseConfigEndpointTest.php` — new
- `tests/Feature/FamilyCreationLoggingTest.php` — new
- `SELF-HOSTING.md` — Single-Family Policy section
- `docs/ROADMAP.md` — #138 marked DONE
- `docs/ARCHITECTURE.md` — soft-enforcement note

## 2026-04-29 — Overnight quick-wins batch: 4 small issues

Four small, independent issues completed in a single overnight unattended session. Each one lives on its own feature branch (no PRs opened — Greg will push and PR each in the morning). Listed in suggested merge order:

### Persist shopping window filter ([#163](https://github.com/gregqualls/kinhold/issues/163))

Branch: `feature/163-shopping-window-persistence`. The shopping window selector (All / Next 2d / Next 3d / This week) reset to "All" on every page load. Now persisted to localStorage under `kinhold_shopping_window`, matching the existing pattern in [`stores/calendar.js`](resources/js/stores/calendar.js). Allowlist-validates the loaded value so a stale or tampered key falls back to `'all'`. Guards `window.localStorage` access for non-browser test environments.

### Hide non-Anthropic chatbot providers ([#201](https://github.com/gregqualls/kinhold/issues/201))

Branch: `feature/201-hide-non-anthropic-providers` (extended on `chore/overnight-quick-wins` after `/review`). `AgentService::availableProviders()` listed Anthropic, OpenAI, and Google in the BYOK picker, but the agent loop only constructs `AnthropicProvider` and `resolveApiKey()` returns null for non-Anthropic slugs — so a parent who pasted an OpenAI key was silently downgraded to the platform Anthropic key (and after [#137](https://github.com/gregqualls/kinhold/issues/137), would also hit the daily-message cap they didn't expect).

The original commit removed the OpenAI/Google entries from `availableProviders()` and updated the BYOK card helper text. Code review caught two follow-on gaps:

1. **API validation still accepted the removed slugs.** `SettingsController` had a hardcoded `'in:anthropic,openai,google'` rule, so a direct API or MCP caller could persist `ai_provider: openai` even though the SPA picker no longer offered it. Validation now derives from `AgentService::availableProviders()` via `Rule::in(array_column(...))` so the picker, the agent loop, and the validation rule share one source of truth — silently-removed slugs can never be persisted again.
2. **Already-saved bad values still caused silent downgrades.** Families who saved `ai_provider: openai/google` before this change still had that value in `families.settings`; `resolveApiKey()`'s slug guard would return null and the loop would fall back to the platform Anthropic key. New migration `2026_04_30_120000_normalize_stale_ai_provider_settings` walks all family rows, resets affected ones to `ai_provider: anthropic` + clears the now-mismatched `ai_api_key` + sets `ai_mode: kinhold` (back to platform AI rather than half-broken BYOK), and logs each affected family so support can reach out if a real customer was downgraded silently.

UI polish from the same review pass: the BYOK provider grid was a `grid-cols-1 sm:grid-cols-3` layout, which left a single Anthropic button in column 1 with two empty columns to its right. Now computes column count from `aiProviders.length` so it stays full-width while there's only one provider and grows back when adapters land.

### Brand-aligned email theme + visible button text ([#104](https://github.com/gregqualls/kinhold/issues/104))

Branch: `feature/104-email-brand-colors`. While verifying the brand-color update, discovered that the existing [`themes/kinhold.css`](resources/views/vendor/mail/html/themes/kinhold.css) had two real bugs: (1) it used `#B38A50` (the brand's pressed/active state) where `#C4975A` (Primary Gold) belonged, plus the wrong text and background tokens; (2) Laravel mail themes REPLACE `default.css` rather than stack on top of it, so the existing partial-overrides file was missing all structural CSS — and most damaging, missing `.button { color: #fff }`, which let `a { color: #B38A50 }` paint button text the same color as the button background → invisible CTAs. Confirmed by rendering `FamilyInviteNotification` to HTML and grepping inlined styles.

Rewrites `kinhold.css` as a complete theme: full structural ruleset plus brand-correct colors throughout (Warm Ivory `#FAF8F5` page bg, Warm White `#FFFFFF` cards, Muted Gold `#C4975A` CTAs with explicit `color: #FFFFFF !important` to defeat the link cascade, Near Black `#1C1C1E` text, 12px card radius matching the SPA's `rounded-xl`). Heading font stack adds `'Plus Jakarta Sans', 'Inter'` ahead of the existing system-ui chain. File header notes the REPLACE-not-merge behavior so future editors don't repeat the mistake. Email size grew from 3.4kB to 13.3kB as the previously-missing structural rules now inline correctly. 6 mail/notification tests pass.

### Native Windows dev setup docs ([#174](https://github.com/gregqualls/kinhold/issues/174))

Branch: `feature/174-windows-dev-docs`. Adds a "Native Windows setup" section to [CONTRIBUTING.md](CONTRIBUTING.md) covering ~9 distinct gotchas a fresh Windows contributor would otherwise rediscover one at a time: PHP 8.4+ requirement (composer.lock vs composer.json), winget `--id` to dodge silent no-op, PATH refresh requires shell restart, dead PATH entries after winget uninstall, php.ini activation + Laravel extension list, Composer-Setup.exe (not winget), PostgreSQL/Redis/Node winget ids, line-ending workaround pending [#173](https://github.com/gregqualls/kinhold/issues/173), pre-commit hook self-discovery (no `--no-verify`). Frames the native flow as opt-in; the recommended path for most Windows contributors remains the Docker self-host flow.

## 2026-04-29 — Clear demo user chat on each demo login (v1.6.1)

PR [#204](https://github.com/gregqualls/kinhold/pull/204). The demo family is a single shared multi-tenant account — every visitor signs into the same `mike@demo.local` (or sarah/emma/etc.) user, so the existing user-scoped chat history meant each visitor was greeted by the previous visitor's questions. A seeded canned conversation (including a "what's the wifi password?" exchange in [DemoChatSeeder.php](database/seeders/DemoChatSeeder.php)) made the demo feel like someone else had been poking around.

Real families are unaffected — chat stays permanent for them. Only the demo login flow wipes.

- [`AuthController::demoLogin`](app/Http/Controllers/Api/V1/AuthController.php) deletes that demo user's `ChatMessage` rows before issuing the token, so each visitor starts blank
- `DemoChatSeeder` removed from the [`DatabaseSeeder`](database/seeders/DatabaseSeeder.php) run list (the canned chat got wiped on first visitor login anyway, so seeding it was pointless). Seeder file kept on disk in case we want to bring a "tour script" version back later
- New [`tests/Feature/DemoLoginTest.php`](tests/Feature/DemoLoginTest.php) covers the wipe and verifies other demo users' chat is untouched (so logging in as Mike doesn't nuke Sarah's history)

Patch bump on top of the AI usage limits release: 1.6.0 → 1.6.1.

## 2026-04-29 — AI assistant usage limits with plan registry (#137)

Closes [#137](https://github.com/gregqualls/kinhold/issues/137). The hosted chatbot endpoint (`POST /api/v1/chat`) was unthrottled and tracked no usage — one family looping the assistant could run an unbounded Anthropic bill. Stripe billing (#70) is still Phase B, so this lands the limit infra without the billing infra: a per-family **daily message count** cap, applied only when Kinhold's platform key is in use. BYOK families and self-hosted instances bypass automatically (their key, their cost). Hard cap, friendly lockout, no soft overage — that needs billing infra we don't have yet.

**Plan-aware from day one.** The marketing page already announces three paid tiers (AI Lite 50/day, AI Standard 150/day, AI Pro 300/day) plus BYOK; this PR makes those a config registry rather than hardcoded numbers. Each plan is a slug → `{ name, daily_messages, price_monthly_cents, stripe_price_id, public }` row in [`config/kinhold.php`](config/kinhold.php). Adding a tier or tweaking a number is a one-line edit. When Stripe lands in Phase B the webhook only needs to write `families.settings.chatbot.plan = 'standard'` after checkout — no change to the limit code.

**Plan resolution precedence** (in [`AiUsageService::planFor`](app/Services/AiUsageService.php)):
1. `families.settings.chatbot.daily_message_limit` numeric override → synthetic "Custom" plan (admin/support escape hatch — no UI for v1, set via Tinker / DB)
2. `families.settings.chatbot.plan` slug → that plan's row
3. Demo family (`slug === 'q32-demo-family'`) → `config('kinhold.chatbot.demo_plan')` slug → richer baseline so the live demo doesn't trip the cap
4. Otherwise → `config('kinhold.chatbot.default_plan')` slug

**`shouldEnforce()` precedence:**
1. `config('kinhold.self_hosted')` (mirrors `SELF_HOSTED` env var) → bypass
2. `AgentService::usesPlatformKey($family)` returns false → bypass (BYOK)
3. Otherwise → enforce

`SELF_HOSTED` was previously read directly via `env()`; this PR routes it through config so tests can override deterministically with `config()->set()`. The BYOK path is centralized via a new `AgentService::resolveApiKey()` static so the limit service doesn't re-implement decryption.

**Schema.** New `ai_usage_daily(family_id, date, message_count, input_tokens, output_tokens)` keyed by `(family_id, date)` UNIQUE. Bounded row count (one per family per active day). UTC throughout — copy says "Resets at midnight UTC." `firstOrCreate` passes a Carbon instance so the date column's cast normalizes both INSERT and WHERE; passing a raw `'Y-m-d'` string mismatched Laravel's stored `'Y-m-d 00:00:00'` form in sqlite and silently failed the unique-row lookup (caught by tests, fixed in implementation).

**Token capture.** `AnthropicProvider::askWithTools()` now returns `{ content, stop_reason, input_tokens, output_tokens }` — Anthropic responses always include a `usage` block, we were just discarding it. Cache hits (we use `cache_control: ephemeral` on the system prompt + tool definitions) are summed into `input_tokens` via `cache_creation_input_tokens` + `cache_read_input_tokens`. `AgentService::chat` aggregates across the agent loop's iterations so a single user turn that does 5 tool calls writes the full token cost to the daily aggregate, not just the final iteration. Tokens are captured but **not enforced** in v1 — the cap is on message count.

**Controller wiring.** `ChatController::send()`:
- Pre-flight: `if ($usage->shouldEnforce && $usage->isExhausted) → 429 with usage payload` (so the failed call doesn't burn quota or hit Anthropic)
- Post-success: `$usage->recordMessage($family, input, output)` — only when enforced
- Response now includes `usage: { count, limit, remaining, reset_at, enforced, plan: { slug, name } }` so the frontend can render the chip without a second round-trip
- `chat_messages.metadata` now also stores `input_tokens` / `output_tokens` per assistant message, so usage can be reconstructed from message rows if the daily aggregate is ever lost

**Frontend.** New chip above the input row reads `AI Lite · 42 / 50 today` — neutral until 80%, amber 80–99%, red at 100%. When `usage.enforced && usage.remaining <= 0`, the entire input row is replaced with a `card-lg` lockout panel showing "Daily AI Lite limit reached", "You've used all 50 messages for today. Resets in 3h 27m.", and two CTAs: "Use your own Anthropic key" and "Upgrade plan" (both → `/settings` for now; the upgrade path lights up with Stripe in Phase B). The chip stays hidden for BYOK / self-hosted families (`enforced: false`).

The 80%-warning toast was scoped in the plan but pulled — the watcher on the store's computed wasn't firing reliably on first-paint hydration, the chip turning amber is already a strong visual cue, and shipping a half-implemented feature is worse than shipping without it. Revisit in a follow-up if real users miss it.

**MCP-side limits not built.** MCP is one-directional (Claude Desktop calls Kinhold tools; the customer's Claude account pays Anthropic, not Kinhold), so it doesn't burn the platform budget. The marketing page's "heavier MCP users should consider BYO key" copy is forward-looking; revisit if/when Kinhold-side MCP elaboration becomes a real cost. Recipe import keeps its existing 20/hr-per-family throttle ([AppServiceProvider.php:41-43](app/Providers/AppServiceProvider.php)) — separate concern with separate budget.

**Tests.** New `tests/Unit/AiUsageServiceTest.php` (13 cases) and `tests/Feature/ChatRateLimitTest.php` (6 cases) cover plan precedence, BYOK and self-hosted bypass, 429 response shape, demo-family resolution, token persistence to both `chat_messages.metadata` and the daily aggregate. Full suite: 155/155 (was 131; +24 new, no regressions). PHPStan clean, Pint clean on touched files, Vite build green. Browser verification confirmed: chip text/colors at 0/50 (neutral), 42/50 (amber), and lockout state at 50/50 (CTAs + reset countdown).

**Files**
- `database/migrations/2026_04_29_120000_create_ai_usage_daily_table.php` — new
- `app/Models/AiUsageDaily.php` — new
- `app/Services/AiUsageService.php` — new
- `app/Services/AiProviders/AnthropicProvider.php` — `askWithTools` returns token counts
- `app/Services/AgentService.php` — sums tokens across agent loop; static `resolveApiKey` + `usesPlatformKey` helpers
- `app/Http/Controllers/Api/V1/ChatController.php` — pre-flight check, recordMessage, usage payload, history endpoint also returns usage
- `config/kinhold.php` — `chatbot.plans` registry, `default_plan`, `demo_plan`, `self_hosted`
- `.env.example` — new env vars documented
- `resources/js/stores/chat.js` — `usage`, `limitReached`, `usagePercent`, `applyUsage()`
- `resources/js/views/chat/ChatView.vue` — chip + lockout panel
- `tests/Unit/AiUsageServiceTest.php`, `tests/Feature/ChatRateLimitTest.php` — new

## 2026-04-29 — GDPR data export: synchronous ZIP download (#96)

Closes the second half of [#96](https://github.com/gregqualls/kinhold/issues/96) — account deletion shipped previously, data export was the missing piece. Triggered by a real user scenario: someone created a duplicate Google OAuth account and had no way to retrieve their data. Self-hosted families need it too. Implements GDPR Article 15 (right of access).

**Approach.** One service, one controller method, one route, one button, one feature test. Synchronous, single request, no queue, no email, no temp-file-with-signed-URL — just stream the ZIP back as the response. Mirrors the existing account-deletion shape (same Settings location, same `auth:sanctum` + `throttle:5,1` middleware, same inline-handler frontend pattern).

**`UserDataExportService` (new).** Builds an in-memory ZIP via PHP's bundled `ZipArchive` (writes to a tmp buffer, reads back, unlinks before responding — never leaves the request). Eight per-domain JSON files plus a top-level `manifest.json`:

| File | Source | Scope |
|---|---|---|
| `user.json` | `User` | self; `password`, `remember_token`, OAuth tokens, 2FA secrets hidden |
| `tasks.json` | `Task` (+ `task_tag` pivot) | `created_by` OR `assigned_to`. Each row tagged with `_role: creator/assignee/both` |
| `vault.json` | `VaultEntry` (+ `documents`) | `created_by` OR `vault_permissions.user_id`. Calls `getDecryptedData()` server-side and returns plaintext under `data`; sets `encrypted_data: null`. Documents bundled into `vault-documents/{id}/...` |
| `points.json` | `PointTransaction`, `PointRequest`, `RewardPurchase` | `user_id` |
| `badges.json` | `Badge` via `user_badges` | join with `earned_at`, `awarded_by` from pivot |
| `chat.json` | `ChatMessage` | `user_id` |
| `calendar.json` | `CalendarConnection` | `user_id`. Defensive `makeHidden` on `access_token`, `refresh_token` even though already in `$hidden` |
| `food.json` | `Recipe` (+ ingredients/tags), `ShoppingList` (+ items), `MealPlan` (+ entries), recipe-only `Rating` | `created_by` (or `user_id` for ratings). Recipe images bundled into `recipe-images/{id}/...` |

Plus avatar bundling under `avatar/{filename}` if `$user->avatar` matches `avatars/*`. `manifest.json` carries `version: '1.0'`, `exported_at` (ISO8601), `user_id`, `family_id`, `app_version`, `files: [...]`. Top-of-method guardrail: `set_time_limit(120); ini_set('memory_limit', '512M');` with a one-line comment that the trigger to switch to ZipStream-PHP is hitting these ceilings — no pre-tuning.

**Controller + route.** `SettingsController::exportData(Request, UserDataExportService): Response` next to `deleteAccount`. New route `POST /api/v1/settings/account/data-export` next to the delete route, same middleware stack. POST chosen over GET because the action has side effects (decrypts vault data, writes log entries) and to avoid browser caches and referrer leakage.

**Password re-confirmation, with OAuth bypass.** Initial plan skipped this for "duplicate-account recovery"; security review pushed back — Google/Apple/Twitter/iCloud all require recent password re-auth, and the blast radius if a session or bearer token is compromised is the user's full vault in plaintext. Endpoint now requires `password` for accounts with one (`Hash::check`, mirroring `deleteAccount`); OAuth-only accounts (no `password`) still skip the check so the recovery scenario still works. Frontend gates the download behind a confirmation modal that surfaces the password field only when `currentUser.has_password !== false` (new field on `UserResource`). Modal also warns the user that the resulting ZIP contains decrypted vault data and should be stored securely.

**Audit log.** Each successful export emits `Log::info('user.data_export', [user_id, family_id, ip, user_agent])`. Structured enough to grep via Upsun logs if a user later reports a leak; doesn't grow a new table for an MVP-scale signal.

**Frontend.** New "Your Data" SettingsSection above Danger Zone in the parent view, plus a matching `card-lg` block above the child Danger Zone (mirroring how the delete-account block is duplicated for child accounts). Inline `handleExportData` mirrors `handleDeleteAccount` style: axios POST with `responseType: 'blob'` → `URL.createObjectURL` → trigger an anchor-click download → `revokeObjectURL`. Blob over a tokenized navigation URL because sanctum auth lives in axios headers — `window.location` would lose them and force inventing a one-time download token (more code, more attack surface).

**Vault permission scope.** "Entries you own or have access to" includes shared entries: an entry created by parent A and granted to parent B appears in both their exports. Once shared, B is entitled to a copy under right-of-access. A dedicated test (`test_shared_vault_entry_appears_in_both_owner_and_grantee_exports`) enforces this.

**Tests.** New `tests/Feature/UserDataExportTest.php` (PHPUnit class-style, matching `SecurityTest`) covers six cases: 401 unauthenticated, ZIP contains expected files, scoping isolation across families, vault decryption in output, calendar token redaction in body, shared-vault visibility. 131/131 pass (was 125, +6 new). PHPStan clean. `npm run build` succeeds; `Export My Data` text and `/settings/account/data-export` URL confirmed present in the production bundle.

**Out of scope (deliberately not built).** Queue/job, two-step "request export → download later", "your export is ready" email, encryption of the export file at rest, signed download URLs, scheduled re-exports, anonymization features, settings to choose what to export, changes to the existing account-deletion flow. The default queue driver is `sync` and `app/Jobs/` doesn't exist; family-sized data fits comfortably in one request, and the comment at the top of `buildExport` documents the upgrade trigger.

**Files**
- `app/Services/UserDataExportService.php` — new
- `app/Http/Controllers/Api/V1/SettingsController.php` — `exportData` action
- `routes/api.php` — new POST route at line 300
- `resources/js/views/settings/SettingsView.vue` — "Your Data" sections (parent + child) + `handleExportData`
- `tests/Feature/UserDataExportTest.php` — new

## 2026-04-29 — MCP consolidation: 20 tools → 7 domain routers + Phase F Step 8 food coverage

The Kinhold MCP server exposed 20 separately-registered tools, each with its own JSON schema injected into every model call. With ~5,000 tokens of tool definitions burning on every turn whether tools were used or not, the AI chatbot's context budget was shrinking fast — and adding the planned food-MCP coverage (Phase F Step 8) would have made it worse. This pass rebuilds the MCP server around domain consolidation + module-gated registration.

**New tool layout (7 tools, down from 20):**

| Tool | Replaces | Module gate |
|---|---|---|
| `kinhold-family` | view-family, get-settings, search-family, manage-dashboard | Always on (core) |
| `kinhold-calendar` | view-calendar, manage-featured-events | `calendar` |
| `kinhold-tasks` | manage-tasks, complete-task, manage-tags | `tasks` |
| `kinhold-food` | *(new — covers Phase F Step 8)* | `food` |
| `kinhold-points` | view-points, manage-points, manage-point-requests, manage-rewards, purchase-reward | `points` |
| `kinhold-vault` | manage-vault, manage-vault-access, list-playbooks, get-playbook | `vault` |
| `kinhold-achievements` | manage-badges, view-earned-badges | `badges` |

Each consolidated tool dispatches by an `action` enum: `kinhold-tasks` accepts `task_list`, `task_create`, `tag_list`, etc. — same pattern as the old `manage-*` tools used internally, just extended across full domain boundaries. Action enums are documented in each tool's `#[Description]` with a per-action params matrix so the LLM can pick correctly without us inflating the JSON schema with conditional `oneOf` constructs.

**Module-gated registration via `shouldRegister()`** — every domain tool except `kinhold-family` implements `Concerns\RequiresModule`, which calls `Family::userHasModuleAccess(static::MODULE, $user)`. Families that have `food` or `vault` disabled never receive those tools' schemas — closest thing to deferred loading we can do today, since `laravel/mcp` 0.6.4 doesn't support Anthropic-style Tool Search / `tools/list_changed` upstream yet. (Tracked separately for Phase 2.)

**`kinhold-food` (new — closes #155, closes #67)** — 47 actions across recipes, shopping, meal plans, meal presets, and restaurants. Wraps the existing service classes (`RecipeService`, `RecipeImportService`, `ShoppingListService`, `MealPlanService`, `RestaurantImportService`) so MCP and the API share identical business logic. Photo upload is the one gap — multipart isn't available over MCP, so `recipe_import_photo` and image uploads fall back to the API; URL-based imports (`recipe_import_url`, `restaurant_import`) work end-to-end.

**Naming convention** — tool names switched from verb-led (`view-calendar`, `manage-tasks`) to domain-prefixed (`kinhold-calendar`, `kinhold-tasks`) so the LLM's tool list reads as a domain inventory rather than action grab-bag. Backward-compat is not preserved — single-user instance, no external clients pinned to old names.

**Workflow fix rolled in** — also patches [.claude/commands/kickoff.md](.claude/commands/kickoff.md) and [.claude/commands/cleanup.md](.claude/commands/cleanup.md) to surface and handle diverged `local-main vs origin/main`. Local main had been silently rotting between sessions because `git pull origin main` (no `--ff-only`, no error reporting) does nothing useful on diverged history. New: explicit `git rev-list --left-right --count` check, fast-forward if behind-only, surface diverged case loudly with a recovery prompt. Same change applied to both commands — kickoff now refuses to start fresh work on stale main, and cleanup handles the squash-merge dupe pattern correctly.

**Files**
- `app/Mcp/Tools/Concerns/RequiresModule.php` — new trait
- `app/Mcp/Tools/Kinhold{Family,Calendar,Tasks,Food,Points,Vault,Achievements}.php` — 7 new consolidated tools
- `app/Mcp/Servers/KinholdServer.php` — `$tools` array reduced to 7 entries
- 20 old tool files in `app/Mcp/Tools/` — deleted
- `.claude/commands/kickoff.md` + `.claude/commands/cleanup.md` — diverged-main handling

**Next steps (Phase 2, separate issue):** when `laravel/mcp` adds Anthropic Tool Search support, mark heavy domain tools (food, vault, points) as deferred so their schemas only ship when the LLM searches for them — projected another ~50% on top of what this PR delivers.

## 2026-04-28 — Fix Google OAuth login loop on production (v1.4.4)

Sign in with Google looped: account-picker → pick account → land back on the login form, repeat. Root cause was a route-binding regression introduced with the MCP/Passport OAuth feature (commit `2ef576b`): `routes/web.php` registered `Route::get('/login', ...)->name('login')` bound to `GoogleAuthController::oauthLogin()`. The comment above it claimed "Uses a separate path so /login stays as the SPA catch-all (no conflict)" but the path was, in fact, `/login`. Every fresh server-side hit to `/login` issued an HTTP 302 to Google OAuth (verified in production via `curl -sI https://app.kinhold.app/login`). Google's callback ran `Auth::login()` + `redirect()->intended('/')`, which sent the SPA to `/`, which redirected to `/login`, which 302'd to Google again — the loop. Users only saw the SPA login form when Vue Router handled `/login` client-side after the SPA was already mounted; cold loads always went to Google.

Fix: moved the Passport-flow path from `/login` to `/auth/oauth-login` while preserving the `name('login')` so Laravel's `Authenticate` middleware (the only consumer of `route('login')`) keeps redirecting unauthenticated MCP/Passport requests to the right place. `/login` now falls through to the SPA catch-all as originally intended. The SPA's stateless OAuth flow (`/auth/google/redirect` → `/auth/google/callback` → `/login?code=...` → `POST /api/v1/auth/exchange`) is unchanged.

Also hardened the SPA's OAuth-error path while debugging: `auth.js` now logs the `/auth/exchange` failure response to console.error and surfaces the server's message in `error.value` (was a bare `catch {}` with a generic message); `LoginView.vue` `onMounted` now reads `authStore.error` and displays it in the existing error slot (was previously set but never rendered). These were defensive fixes — without them the original loop showed no UI feedback.

**Note for production:** the Google Cloud Console authorized redirect URIs do not need to change — `/auth/google/oauth-callback` is still valid for the (renamed) MCP path, and the SPA login flow still uses `/auth/google/callback`.

## 2026-04-28 — Fix: dashboard widgets empty on first demo login (v1.4.3)

Fresh visitors who landed on `/demo`, picked a family member, and got navigated to `/dashboard` saw the chrome (sidebar, mobile nav, header) appear but the page body still showed the demo picker — no widgets, no `DashboardView`. A manual browser refresh consistently fixed it. The same wedge affected fresh `/login` and `/register` → `/dashboard` flows; less commonly noticed because users typically don't watch closely.

**Root cause** — `App.vue` wrapped the `<RouterView>` slot in `<Transition name="page-fade" mode="out-in">`. On the pre-auth → authed boundary (Demo/Login/Register → Dashboard), Vue's transition state machine wedged: the leaving view stayed frozen with both `page-fade-leave-from page-fade-leave-active` AND `page-fade-enter-from` on the same DOM element, never advancing to `page-fade-leave-to`. The CSS opacity transition therefore never ran and `transitionend` never fired. With `mode="out-in"`, Vue waits for the leave callback before mounting the new view — that callback never fired, so `DashboardView` never mounted, `onMounted` never ran, `dashboardStore.fetchConfig()` never executed, and the dashboard store wasn't even registered in Pinia. On a refresh the route lands directly on `/dashboard` with no transition, so everything works.

We tried two narrower fixes that both failed verification: (1) switching chrome from `v-if="!isAuthPage"` to `v-show="!isAuthPage"` to avoid mounting Sidebar/TopBar/MobileBottomNav/EasterEggs mid-transition — Dashboard still never mounted; (2) dropping just `mode="out-in"` so leave and enter run in parallel — DashboardView did mount and widgets populated, but the leaving DemoView still froze mid-leave with `leave-active` (no `leave-to`) and stayed visible in the DOM, overlaying the new view. The wedge is internal to Vue's `<Transition>` lifecycle on this specific boundary; the chrome and the mode are both red herrings.

The two original suspect candidates (stale `"me"` resolution in `useWidgetData`, fire-and-forget `fetchAccountSettings`) were also red herrings — `authStore.currentUser?.id` was correctly populated, and no widget reads `services`/`aiReady`. Confirmed via instrumented dev-server reproduction: only `POST /demo-login`, `GET /user`, `GET /settings` hit the backend on a wedged demo login; zero widget fetches, zero `/user/dashboard`.

**Fix** — removed the `<Transition name="page-fade" mode="out-in">` wrapper around `<RouterView>` in [resources/js/App.vue](resources/js/App.vue) and deleted its `.page-fade-*` CSS rules. Route changes now swap views directly via `:key="viewRoute.path"`; old unmounts and new mounts in the same tick with no transition lifecycle to wedge. Cost is the loss of a 100–150ms inter-route opacity fade — a small UX touch the bug had effectively disabled anyway whenever it triggered. Added a comment explaining why a future tidy-up shouldn't reintroduce a `<Transition>` here without first solving the wedge.

**Verification** — verified end-to-end via Playwright on a fresh incognito session: `/demo` → click Mike → `/dashboard` lands with "Good evening, Mike!" greeting, all ten widget cards populate (Welcome, Countdown, Today's Schedule, My Tasks, Family Tasks, Points balance, Leaderboard, Rewards, Badges, Quick Actions), and the full widget API cascade fires (`GET /user/dashboard`, `tasks`, `points/bank`, `points/leaderboard`, `rewards`, `badges`, `calendar/events`, `featured-events/countdown`). Old DemoView is no longer in the DOM. Zero console errors. Sanity check: `/login` still renders chromeless. The other four demo members (Sarah, Emma, Jake, Lily) go through the identical `demoLogin` → `router.push({ name: 'Dashboard' })` code path; only the route component differs across them, not the transition.

**Version** — bumped `config/version.php` from 1.4.2 → 1.4.3.

## 2026-04-28 — Mobile nav: KinBottomNav, AI-aware FAB, More sheet, header compaction, list-default views

Big mobile-chrome pass that retires the old prussian-token `BottomNav.vue` and pulls the rest of the chrome onto the Kin design system. Done iteratively in a single live session with Preview MCP.

**`MobileBottomNav.vue`** — wraps `KinBottomNav` (the glass pill + center FAB from #175) with four slots and the AI-aware FAB. Schedule and Meals open inline popovers above the pill (Calendar/Tasks; Food/Shopping), with outside-click, Escape, and route-change dismissal. Slot 1 dynamically swaps between **Home** and **Points** depending on whether AI is usable — when the FAB takes over Home, Points is promoted to slot 1 so Home doesn't appear twice. Module gating collapses disabled children groups, degrades single-child groups to direct links, and fills empty positions from a priority list (Points → Vault → Settings) so `KinBottomNav`'s 4-item validator never fires.

**`MoreSheet.vue`** — `KinModalSheet` (bottom sheet on mobile, centered modal on desktop) listing Points, Rewards, Badges, Vault, Settings, and Sign Out. Accepts an `excludeKeys` prop so MobileBottomNav can pass the active-slot ids and dedupe (when Points is in slot 1, Points is hidden from More). Sign Out calls `authStore.logout()` then pushes to `/login`, mirroring the Sidebar pattern.

**AI-aware FAB** — reads `aiReady` from the auth store. When AI is usable (kinhold mode + platform key, or byok mode + saved key), the FAB shows a sparkle icon and routes to `/chat`. When AI is off, it shows a Home icon and routes to `/dashboard`, while slot 1 swaps to Points. The store recomputes `aiReady` after any AI-settings save in SettingsView, so the FAB flips without a reload. FAB styling (warm-charcoal gradient + warm-white icon) extracted to a scoped `.mobile-fab` class — the hex values are bespoke to this surface and have no token equivalents.

**Glass alpha tuned** — `KinBottomNav` background reduced from `rgba(255,255,255,0.72)` → `0.55` (light) and `rgba(28,27,25,0.75)` → `0.60` (dark) so the glass effect reads as visibly translucent over uniform page backgrounds.

**Auth-store consolidation** — added `aiReady` ref + `fetchAccountSettings()` action that hits `/settings` once and updates both `services` and `aiReady`. `fetchServices` and `fetchAiReady` are now thin aliases pointing at the same fetcher, dropping duplicate `/settings` calls on init.

**Mobile resize sweep** — quick wins across the affected views at 375px: `flex-wrap` on the DashboardToolbar button row; `flex-wrap` on the RewardsView header; `flex-col sm:flex-row` stacking on RecipesTab search/controls; Tailwind height classes (`h-14`, `h-20`, `h-10`) replacing inline `style="height:…"` on LeaderboardWidget podium bars; `gap-3 sm:gap-4` on VaultEntriesView entry rows.

**Header compaction pass** — eight authenticated views got tighter mobile headers (h1 `text-2xl` → `text-lg md:text-2xl`; subtitles `hidden md:block`; outer padding `pt-4` → `pt-3`; inter-row spacing `mb-6` → `mb-3 md:mb-6`). Saves ~64px vertically per page above the fold. Touched: TasksView, CalendarView, FoodView (+ RecipesTab + RestaurantsTab), VaultCategoriesView, VaultEntriesView, VaultEntryView, PointsFeedView, PointsHistoryView, RewardsView.

**Recipes & Restaurants polish** — default view flipped from grid (`localStorage.getItem(…) || 'grid'` → `|| 'compact'`) so families see scan-friendly rows with thumbnails + tags out of the box. Toolbar Add Recipe/Restaurant pills removed; `FloatingActionButton` gained a `mobileOnly` prop (default `true` to preserve Tasks behavior) and is now the always-visible add affordance for these tabs at every breakpoint.

**Points page mobile-scroll fix** — `PointsFeedView` was using `h-full overflow-hidden` with internal scroll on the Activity feed (a desktop fixed-region pattern). On mobile this clipped Hero + Leaderboard + Activity off the bottom. Gated those rules behind `lg:` so mobile flows naturally as a single scroll column.

**`LeaderboardPodium` extracted** — single shared component used by both `LeaderboardStrip` (Points page) and `LeaderboardWidget` (dashboard). Trophy crown sits centered on top of the 1st-place avatar via absolute positioning relative to the avatar wrapper. Eliminates 25-line inline duplication between the two surfaces.

**`KinButton` specificity fix** — wrapped the base `.kin-btn` selector in `:where()` so its 0-specificity base styles let consumer-side `hidden md:flex` Tailwind classes win without needing `!important`. Several views in this PR (Vault, Rewards) rely on responsive-hide for desktop-only toolbar buttons; previously the scoped CSS data-attribute specificity was beating the utilities.

**`App.vue`** — swapped the import to `MobileBottomNav`, added `fixed bottom-3 left-3 right-3 z-30` positioning + `pb-24 md:pb-0` on `<main>` so content clears the floating pill.

**Z-index fix** — popover backdrop dropped from `z-30` (collided with the bottom-nav wrapper, intercepting popover taps) to `z-20`, so Schedule/Meals popover links now navigate.

**`BottomNav.vue` deleted** — zero imports remain.

## 2026-04-27 — Demo landing page at `/demo`, fix dashboard-flash on boot

New `DemoView.vue` at `/demo` — a full-page version of the existing demo modal that the marketing site can deep-link to instead of `/login`. Reuses the same five Johnson-family member picker and `authStore.demoLogin()` action as the modal, wrapped in a Kin design-system layout with intro copy ("Meet the Johnson family"), a "What's inside" highlights row (calendar/tasks, vault/recipes, points/badges), and footer links to sign in or create an account. Route is `requiresGuest`, so authenticated visitors bounce to Dashboard. `'Demo'` added to App.vue's chromeless-page list.

Also fixed a brief authenticated-chrome flash on SPA boot when visiting `/login` or `/demo`. The router's async `beforeEach` awaits `initAuth`, so `route.name` is undefined for the first frame — App.vue's `isAuthPage` previously evaluated to `false` and rendered Sidebar/TopBar/BottomNav before the route resolved. Now `isAuthPage` returns `true` (chromeless) until `authStore.initialAuthChecked` flips, eliminating the flash without changing post-boot behavior.

## 2026-04-27 — Removed landing page from SPA (#134)

`LandingView.vue` and its five screenshot assets deleted. `/` now redirects to `/login` via a Vue Router redirect; the `meta.isPublic` guard branch (dead code) removed. The existing `requiresGuest` guard on Login already handles authenticated visitors (→ Dashboard) and first-boot (→ Register). Inbound links in PrivacyPolicyView, TermsView, and NotFoundView updated from `to="/"` to `to="/login"`. The server-side email-verification-error redirect in `routes/web.php` updated from `/?verify_error=invalid` to `/login?verify_error=invalid`; minimal `onMounted` handling added to LoginView to surface the error message. README landing-page bullet removed; LAUNCH-PLAN.md marked archival.

## 2026-04-27 — Dashboard widgets revisited: full Kin treatment

When 6.1 Dashboard shipped earlier, the *shell* (DashboardView, DashboardWidget, DashboardToolbar, WidgetPickerModal) got the Kin treatment but the individual widgets only got mechanical KinSkeleton/KinEmptyState swaps. After every other view caught up, the widgets stuck out as the visually-stale pocket of the app. This pass closes that gap.

**11 widgets touched** (CountdownWidget already done):

- **WelcomeWidget** — token sweep on greeting + date.
- **PointsSummaryWidget** — restructured to a small `KinHeroMetricCard`-style hero: dropped the trophy-icon-square-plus-label-plus-value triplet; replaced with vertical layout — uppercase kicker label + `text-4xl` font-mono hero number + `pts` suffix in tertiary ink. Recent activity row badges use `text-status-success bg-status-success/10` / `text-status-failed bg-status-failed/10` pills.
- **BadgesWidget** — header standardized; intentionally **kept `BadgeIcon`** (KinAchievementTile's 108×108 hex tiles are too big for dashboard mini-grids) and just token-swept the surrounding chrome.
- **ActivityFeedWidget** — header standardized; description/meta tokens swept; points-pill rewritten to use status tokens.
- **FamilyTasksWidget** / **MyTasksWidget** / **FilteredTasksWidget** — header standardized; task rows now have `border-b border-border-subtle last:border-b-0` for visual rhythm (matches the Tasks-view card pattern); checkbox states use status-success + accent-lavender-bold; FilteredTasksWidget tag pills (dynamic `:style`) preserved.
- **LeaderboardWidget** — header + View Feed link standardized; current-user highlight → `bg-accent-lavender-soft/40`; podium gradients (sand/lavender/amber) intentionally preserved as bespoke domain visuals.
- **TodaysScheduleWidget** — header standardized; row dividers → `border-border-subtle`; per-event color accent stripe preserved.
- **QuickActionsWidget** — biggest visual upgrade: each tile is now a proper Kin card (`bg-surface-raised border border-border-subtle rounded-card hover:border-accent-lavender-bold/40 hover:shadow-resting`) with a circular `bg-accent-lavender-soft/50` icon container holding the action's icon. Mirrors the FoodCard / RecipeCard tile philosophy.
- **RewardsWidget** — header + View All link standardized; `FeaturedRewards` child component left bespoke (separate scope).

**Universal standardization** across all widgets:
- "View All" / "View Feed" / "View Calendar" links: `class="text-xs font-medium text-accent-lavender-bold hover:opacity-80 transition-opacity"`
- Widget title row: `text-ink-primary` heading + `text-accent-lavender-bold` leading icon
- All `prussian-*` / `lavender-*` / `wisteria-*` / `sand-*` / `red-*` / `green-*` / `emerald-*` legacy tokens replaced with their Kin equivalents

**Verified live**: Mike's demo dashboard renders Rewards Shop (Weekend Trip Pick / Extra Allowance / Sweets), Badges (29-tile grid with 4 earned, hex shapes intact), and the upgraded QuickActions 2×3 grid with clean Kin tiles. No console errors.

**Visual overhaul is now end-to-end consistent.** Every authenticated view + every widget + every auth/onboarding surface wears the Kin design system.

## 2026-04-27 — Tier 6.6–6.10 Phase 1: Vault, Chat, Settings, Onboarding, Auth onto Kin

Closed out the rest of Tier 6 in a single dispatch: 5 view areas, ~6,300 LOC, refactored in parallel by sub-agents under strict Phase 1 rules (token sweep + targeted Kin component swaps; no logic changes; structural form/encryption/editor wiring untouched).

### 6.7 Chat ([resources/js/views/chat/ChatView.vue](resources/js/views/chat/ChatView.vue))
- Setup-prompt empty state ("No API Key") → `KinEmptyState` (sun accent) + `KinButton primary` CTA to /settings.
- Welcome empty state → `KinEmptyState` (lavender) with the suggested-question rows kept as bespoke prompt cards token-swept.
- Composer Send button → `KinButton variant="primary" icon-only` carrying `PaperAirplaneIcon`.
- Full token sweep on message bubbles + composer.
- Deferred: `<textarea>` kept native (auto-resize relies on direct `scrollHeight` + template ref). Message bubbles stayed bespoke — chat alignment with user/assistant sides differs structurally from `KinActivityRow`.

### 6.8 Settings ([resources/js/views/settings/SettingsView.vue](resources/js/views/settings/SettingsView.vue) + [SettingsSection.vue](resources/js/components/settings/SettingsSection.vue))
- ~340 token replacements across the 2,440-line view + the section component.
- 8 native `<input>` → `KinInput` (invite email, AI model + API key, ICS URL/name, default-task-points trio).
- 3 `<select>` → `KinSelect` (leaderboard period, week-start day, member role) with hoisted option arrays.
- 3 boolean toggles → `KinSwitch` (kudos-cost + 2× email-preference rows).
- Deferred: `BaseModal` × 5 (project-wide wrapper — separate refactor pass), 2 `<ToggleSwitch>` instances using `#thumb` slot for Sun/Moon icons (KinSwitch has no thumb slot), `class="card-lg"` global utility, radio-group / multi-select patterns (wrong primitive for KinSwitch).

### 6.9 Onboarding ([resources/js/views/onboarding/](resources/js/views/onboarding/))
- **OnboardingView shell** — Next/Back/Skip/Finish → `KinButton`; progress dots tokenized to `bg-accent-lavender-bold` / `bg-surface-sunken`.
- **WelcomeStep** — `KinInput` for name; `KinSelect` for timezone (computed `timezoneOptions`).
- **FeaturesExplainerStep** — accessible feature cards → `KinGradientCard` with per-feature variant map (sun/mint/warm/cool/lavender). Locked cards → `KinFlatCard`.
- **FeaturesStep** — feature cards → `KinFlatCard padding="sm"`; per-member access checkboxes → `KinCheckbox`. Mode-pill row left bespoke (4-state segmented).
- **CalendarStep** — "Connected" success → `KinFlatCard`. Connect-Google OAuth button left bespoke (token-swept).
- **TagsStep** — "How it works" panel → `KinFlatCard`. Preset grid retained (descriptions don't fit `KinChip`'s label-only API), token-swept.
- **InviteStep** — Member rows + invite-code panel + non-parent panel → `KinFlatCard`. Inputs/select → `KinInput`/`KinSelect`. Action buttons → `KinButton`.
- **CompleteStep** — token-swept.

### 6.10 Auth ([resources/js/views/auth/](resources/js/views/auth/))
- **LoginView.vue** — both forms (login + pending-link) wrapped in `KinFlatCard padding="lg"`. `BaseInput` ×3 → `KinInput`. `BaseButton` ×4 → `KinButton` (Sign In primary, Link & Sign In primary, Cancel ghost, Google secondary with `#leading` SVG slot). `KinCheckbox` for Remember me. Error blocks → `bg-status-failed/10 border-status-failed/30 text-status-failed`. Page wordmark + centered layout untouched.
- **RegisterView.vue** — same playbook: `KinFlatCard`, 6 `BaseInput` → `KinInput`, primary submit + Google secondary → `KinButton` with `#leading` SVG slot. Family-mode toggle buttons stayed bespoke (active state retains `bg-kin-gold text-white`).

### 6.6 Vault ([resources/js/views/vault/](resources/js/views/vault/) + [resources/js/components/vault/](resources/js/components/vault/))
- **VaultCategoriesView** (~509 lines) — header KinButtons; KinSearch; KinEmptyState + KinButton CTA; 2× KinModalSheet (add/edit category, delete confirm); 3× KinInput; 1× KinSelect; 4× KinButton (modal actions).
- **VaultEntriesView** (~313 lines) — header Add → KinButton; KinSearch; KinEmptyState + KinButton; KinModalSheet; KinInput; 2× KinButton.
- **VaultEntryView** (~626 lines) — token sweep ~30 sites; 2× KinModalSheet (Share, Edit); 2× KinSelect (Share form); KinInput + KinSelect for Edit; 4× KinButton modal actions.
- **MarkdownEditor.vue** — untouched (passthrough wrapper).
- **MilkdownEditorCore.vue** — token-sweep on the editor wrapper/toolbar chrome only. Markdown content typography (`prose-vault` styles) intentionally left for a dedicated palette pass.
- **SensitiveField.vue** — token-sweep on label/masked text/reveal/copy. Encryption/decryption logic untouched.
- Deferred: sensitive-key/value `<input class="input-base">` inputs (excluded by security rules), category icon color lookup tables (`getCategoryBgClass` / `getCategoryTextClass`) — palette pass needed.

### Verified in preview

- `/chat` — empty state with lavender chip icon, "Kinhold Assistant" hero, suggested-question cards, composer + Send button.
- `/settings` — Family Settings page with collapsible `SettingsSection` cards (Family / Tasks & Points / AI & Integrations / Feature Access / Food).
- `/vault` — header + KinSearch + 5 category tiles (Education, Financial, Insurance, Legal, Medical) with their accent-tinted icon squares and count badges.
- `/login` — KinFlatCard form with KinInput email/password, KinCheckbox Remember me, primary KinButton Sign In, Or-try-demo + Sign-up links.
- No new console errors after fresh reload on any of the four pages.

### Phase 2 deferrals (carry forward)

- `BaseModal`, `BaseButton`, `BaseInput` are still wrappers used across the codebase. They've been pushed past for now — refactoring them touches API surface (e.g., `:show` → `:model-value`, `#footer` → `#actions`) and warrants a single coordinated pass once every consumer is on Kin tokens.
- `class="card-lg"` global utility — global CSS class. Either rename to a Kin token utility or wrap callers in `KinFlatCard`.
- Vault category icon palette tables — domain-specific color set, separate audit.
- Settings: ToggleSwitch dark-mode rows (need either thumb-slot extension on `KinSwitch` or a design call to drop the icon).
- Settings: 5 `BaseModal` instances (add-member, remove-confirm, switch-to-profile, demo-delete, delete-account/family).

**Tier 6 is now feature-complete** for the visual overhaul. Every authenticated view + auth onboarding now wears the Kin design system (modulo the wrapper-component deferrals above).

## 2026-04-27 — Tier 6.5 Phase 1: Food module onto Kin design-system

Largest tier yet — ~6,000 LOC across 5 views (FoodView shell + Plans/Recipes/Restaurants/Shopping tabs + RecipeDetailView) and 19 child components. Phase 1 keeps it pragmatic: shell refactor + tab headers onto Kin patterns + a complete token sweep across every Food-related file. Heavy form/picker structural refactors deferred to Phase 2.

### Shell + tabs

- **FoodView.vue** ([resources/js/views/food/FoodView.vue](resources/js/views/food/FoodView.vue)) — bespoke 4-tab pill row replaced with `KinTabPillGroup variant="underline"` (matches the editorial gold-underline pattern the food module already used). 52 lines down to a clean shell.
- **RecipesTab.vue** — search → `KinSearch`; view-mode toggle stays bespoke icon button (token-swapped); Add Recipe → `KinButton primary`; sort dropdown → `KinSelect`; Favorites filter → `KinChip variant="filter" color="peach"`; tag filter chips → `KinChip` with `customColor` per tag; empty → `KinEmptyState` with `#cta` slot; create form modal → `KinModalSheet`. Net 424 → 366 lines.
- **RestaurantsTab.vue** — same playbook: KinSearch + KinButton + KinChip filter row + KinEmptyState. Bespoke restaurant cards left intact for Phase 2.
- **MealsTab.vue** — token sweep on prev/next/today buttons, week-range header, day grid hover states. Layout untouched (the meal-plan grid is heavily domain-specific).
- **ShoppingTab.vue** + ListHeader / AddItemInput / ShoppingListItem / PreShopChecklist / CreateListInline — token sweep + KinButton on save/done/delete actions; KinSelect on the list-picker dropdown; KinModalSheet on inline editing where it fit.
- **RecipeDetailView.vue** — token sweep + KinButton on action buttons; bespoke recipe rendering preserved.

### Child components (token-only sweep)

19 component files token-swept by sub-agents — all `prussian-*` / `lavender-*` / `wisteria-*` / `btn-primary` / `BaseModal` references replaced with the Kin equivalents (`text-ink-*`, `bg-surface-*`, `border-border-*`, `KinButton`, `KinModalSheet`). Files touched include: FoodCard, PhotoUpload, RecipeIngredientPicker, TagPicker, CookLogEntry, FamilyRating, IngredientList, RecipeCard, RecipeImportModal, StepList, MealDaySection, MealEntryCard, MealEntryPicker, MealPlanShoppingModal, MealWeekGrid, AddItemInput, CreateListInline, ListHeader, PreShopChecklist, ShoppingListItem.

Some of these got Kin component swaps where the fit was clean (CookLogEntry → `KinModalSheet` + `KinInput` + `KinTextarea` + `KinButton`; RecipeImportModal → `KinModalSheet`; ListHeader → `KinSelect` + `KinButton` set; AddItemInput → `KinButton` for the Add action; CreateListInline → `KinInput` + `KinButton`). The rest are token-only and structurally unchanged.

The intentional food gold accent (`#C4975A`/`#D4A96A`) is preserved everywhere — that's the brand color for the module and shouldn't fold into a Kin accent family.

### Verified in preview

`/food` Plans tab renders the meal-plan week grid with KinTabPillGroup gold underline; Recipes tab shows KinSearch + filter chips (Favorites peach + Breakfast/Lunch/Dinner/Dessert/Snack/Italian custom-color chips) + empty state with FireIcon + "Add Recipe" CTA; Restaurants tab shows the same filter pattern with restaurant cards rendering correctly; `/shopping` shows the empty-list KinEmptyState + KinInput + KinButton create form. No console errors.

### Phase 2 deferrals

These four heavyweights got token sweeps but kept their bespoke form structure:
- **RecipeForm.vue** (489 LOC) — long branching form with photo upload, ingredient list builder, step list builder, time/serving inputs, tag picker, cook log. Worth a deliberate pass with a `KinForm` row helper if we add one.
- **MealEntryPicker.vue** (350 LOC) — tabbed picker (recipe / restaurant / quick text) with search and source attribution.
- **MealPlanShoppingModal.vue** (344 LOC) — preview-and-pick flow with collapsible per-recipe ingredient pickers and target-list selector.
- **MealWeekGrid.vue** (281 LOC) — the 7-day × 4-meal grid with drag-to-reschedule, copy-day, and per-cell add buttons.

All four would benefit from `KinFormGroup` patterns and a future `KinTabPillGroup` swap on internal sub-tabs. The risk in Phase 1 was high since they couple to multiple stores and have complex local state — better to refactor each in its own session with proper verification.

Tier 6.5 Phase 1 done.

## 2026-04-27 — Tier 6.4: Points + Achievements onto Kin design-system

Big sweep across the Points module (3 views + 6 components) and Achievements / Badges (1 view). Headline change: Achievements now uses `KinAchievementTile` directly — the locked design-system component for badges — replacing bespoke BadgeCard + BadgeIcon stacks per badge.

### Achievements

- **BadgesView.vue** ([resources/js/views/badges/BadgesView.vue](resources/js/views/badges/BadgesView.vue)) — full refactor.
  - Renamed page heading "Badges" → "Achievements" per the [REDESIGN_BRIEF](docs/design/REDESIGN_BRIEF.md) decision (still routed at `/badges` for now).
  - Grid swaps `BadgeCard` for `KinAchievementTile` directly. Adapter helpers map badge data → tile props:
    - `stateFor(badge)` → 'earned' / 'in-progress' / 'locked' / 'hidden'
    - `progressFor(badge)` → normalized 0–1 from `progress / trigger_threshold`
    - `metaFor(badge)` → "Earned" / "X / Y"
    - `accentColorFor(hex)` → hue-bucket map onto lavender / peach / mint / sun (purples → lavender, reds/pinks → peach, greens → mint, yellows/oranges → sun)
    - `iconComponentFor(name)` → memoised functional component that renders just the inner SVG path (KinAchievementTile applies the hex shell)
  - Tabs (All / Earned / Locked) → `KinTabPillGroup` variant=tinted.
  - Create form → `KinFlatCard` wrapper, `KinInput` for name + description + threshold, `KinSelect` for trigger type, `KinCheckbox` for "hidden". Icon + color pickers stay bespoke (custom multi-select grids).
  - "Manually Award" form → `KinSelect` ×2 + `KinButton` primary inside another `KinFlatCard`.
  - Empty state → `KinEmptyState` with mode-aware title/description.
  - Token sweep throughout.
  - **Note:** the existing bespoke `BadgeCard`, `BadgeIcon`, `BadgeProgressBar`, `BadgeShowcase` files are untouched — still used by `BadgesWidget` on the dashboard and possibly elsewhere. They can be migrated later or deprecated piecemeal.

### Points

- **PointsFeedView.vue** ([resources/js/views/points/PointsFeedView.vue](resources/js/views/points/PointsFeedView.vue))
  - Header nav `RouterLink`s → `KinButton` (secondary / ghost) with `to=` prop.
  - Balance section → `KinHeroMetricCard` variant=iridescent with "Spend" CTA routing to /points/rewards. Bank value drives the auto-scaling hero number.
  - Leaderboard split out into its own `KinFlatCard` (was bundled with the balance card).
  - Activity feed wrapped in `KinFlatCard padding="none"` so the divider rhythm reads cleanly. Empty state → `KinEmptyState` (sm). Kudos input strip stays at the bottom on a sunken surface.
  - LeaderboardStrip kept bespoke (267 LOC of podium animations — Phase 2).
- **PointsHistoryView.vue** ([resources/js/views/points/PointsHistoryView.vue](resources/js/views/points/PointsHistoryView.vue)) — back button → `KinButton` ghost icon-only; balance → `KinHeroMetricCard`; transaction list wrapped in `KinFlatCard padding="none"` with hairline dividers; empty → `KinEmptyState`.
- **RewardsView.vue** ([resources/js/views/points/RewardsView.vue](resources/js/views/points/RewardsView.vue))
  - Back + Add Reward buttons → `KinButton`. Bank pill kept inline.
  - Search input → `KinSearch`.
  - Filter pills (All / Affordable / Available) → `KinChip` color="lavender" with `:active` state.
  - Sort dropdown → `KinSelect size="sm"` (with `sortOptions` array).
  - Both empty states → `KinEmptyState` (search-empty uses MagnifyingGlass / lavender; module-empty uses Gift / peach).
  - **Deferred:** `RewardCard` (267 LOC, dual-mode standard + auction with stock/expiry/visibility chips) and `RewardForm` (253 LOC, branching form with icon picker + age range + specific-people picker) — too domain-heavy for this session.
- **BidModal.vue** + **DeductModal.vue** + **RequestPointsModal.vue** — all swapped from inline `BaseModal` patterns to `KinModalSheet`. Inputs → `KinInput`. Member select in DeductModal → `KinSelect`. Action buttons → `KinButton` (Cancel ghost, Submit primary, Deduct danger).
- **KudosInput.vue** — member `<select>` → `KinSelect`; reason input → `KinInput` (preserves @keydown.enter); Give Kudos → `KinButton` primary.
- **PendingRequests.vue** + **FeedItem.vue** — token sweep only. Bespoke colored points pill in FeedItem preserved (it's a domain-specific +/- numeric badge).

### Verified in preview

`/badges` shows the new Achievements grid with the four KinAchievementTile states (earned hex / in-progress with arc / locked ghost / hidden ???). `/points` shows the iridescent KinHeroMetricCard hero with the bank balance and Spend CTA, leaderboard in its own card, activity feed in a third. `/points/history` shows the same pattern minus the leaderboard. `/points/rewards` shows the KinSearch + KinChip filter row + KinSelect sort. DeductModal opens as a centered KinModalSheet with KinSelect + KinInputs + KinButton danger. No new console errors after fresh reload.

### Phase 2 deferrals

- `LeaderboardStrip` (267 LOC) — podium with medal/height-based visual + slide-up animations. No Kin equivalent today; would need a new `KinPodium` component or stay bespoke long-term.
- `RewardCard` (267 LOC) — dual-mode card (standard purchase vs. timed/live auction) with stock / expiry / visibility / countdown badges. Could become `KinPhotoCard` + bespoke meta chips, but the auction state machine deserves a deliberate refactor.
- `RewardForm` (253 LOC) — long branching form with icon picker, color picker, age range, specific-people multi-select. Needs a `KinForm` row helper or systematic decomposition.
- Old bespoke `BadgeCard` / `BadgeIcon` / `BadgeProgressBar` / `BadgeShowcase` left intact (still used by dashboard widget). Worth pruning once dashboard widget migrates too.

Tier 6.4 done.

## 2026-04-27 — Tier 6 Phase 3: Utility rail + editorial day header on Calendar/Tasks

Per the original brief — "right utility rail only on data-heavy pages (calendar, tasks, vault, food)" — Calendar and Tasks now wear the rail on desktop (≥`lg`). Calendar also gets the editorial `KinDayHeader` as the centerpiece for Day view.

### Calendar

- **CalendarView.vue** ([resources/js/views/calendar/CalendarView.vue](resources/js/views/calendar/CalendarView.vue))
  - Two-column desktop layout: main content `flex-1` + `KinUtilityRail` (280px) on the right. Rail collapses on `< lg` (mobile gets a floating Add Event button instead).
  - **Day view centerpiece:** the small `<h2>EEEE, MMMM d, yyyy</h2>` is replaced with `KinDayHeader` size="md" — editorial-scale day number with `clamp(4rem, 12vw, 8rem)` so it's huge on desktop and proportional on mobile. Includes weekday + month + event count + "TODAY" badge when applicable. Prev/next icon buttons flank the header.
  - **Month/Week views** keep the compact navigation pill (prev / title / Today / next) — KinDayHeader's hero scale is overkill for a span title.
  - **Rail content:**
    - `#mini-month` — bespoke 7-col grid (KinMonthGrid would dominate the 280px rail). Today filled lavender, event days dotted, off-month days dimmed; clicks route through the same `onMonthDaySelect` adapter as the main grid.
    - `#filters` — `KinChip` per source (Family Events lavender, Tasks sun, plus one per calendar connection using `customColor`). Toggle off to filter that source out of all three view modes simultaneously.
    - `#actions` — Add Event (primary KinButton) + Today (ghost). Auto-pinned to the rail's bottom by `KinUtilityRail`'s `mt-auto` on `actions`.
  - New state: `now` (DateTime ref for TODAY badge), `sourceFilters` (computed list — built-ins + connections), `sourceFilterState` (reactive on/off map, default-on as filters appear), `passesSourceFilter()` predicate, and three `filtered*` computeds (`filteredWeekEvents`, `filteredDayEvents`, `filteredMonthEventsMap`) feeding the grids/TimeGrids.
  - Removed: the bottom legend (sources are now toggleable in the rail). View-mode tabs stay in the top header. Add Event is gone from the top header — moved to the rail's `#actions`. Mobile gets a floating round Add Event button.

### Tasks

- **TasksView.vue** ([resources/js/views/tasks/TasksView.vue](resources/js/views/tasks/TasksView.vue))
  - Two-column layout matching Calendar: main `flex-1` + `KinUtilityRail` (280px) hidden on `< lg`.
  - **Mobile:** the existing horizontal tag filter row stays (only shown `< lg`).
  - **Desktop rail content:**
    - `#filters` — same chips, but stacked vertically. "All" neutral chip + per-tag chips with `customColor` and incomplete-count.
    - `#actions` — Add Task (primary KinButton, hooks `focusQuickAdd`) + Manage tags icon-only ghost (cog icon).
  - Mobile FAB (`FloatingActionButton`) now `lg:hidden` so it doesn't overlap the rail's Add Task button on desktop.

### Verified in preview

Desktop (1400×900): Calendar renders three-column shell — sidebar / main / utility rail. Day view shows the giant "27" / MONDAY / TODAY / APRIL 2026 / · 2 events row. Tasks shows the vertical chip stack in the rail with Add Task at the bottom. Mobile (375×812): rail hidden on both views, original mobile layouts preserved (horizontal chip strip on Tasks, FAB on Calendar). No console errors.

### Notes

- Rail's mini-month is a separate small implementation (not `KinMonthGrid`) because KinMonthGrid's `min-h-[42px]` cells would inflate the rail. If we ever add a `density="compact"` to `KinMonthGrid`, we can DRY this up.
- Rail width is fixed at 280px — when the brief mentioned "saved views" and "presence" sections that's not yet wired here. Both are easy adds when the data exists (no online-presence tracking yet; saved views is a feature flag away).

## 2026-04-27 — Tier 6 Phase 2: KinSelect, KinChip color escape, KinMonthGrid, KinModalSheet on TaskDetailPanel

Closing the four phase-2 questions Greg flagged in the morning. Library grows by one component, gains an escape-hatch, and Calendar + Tasks both move further onto Kin primitives.

### New library component

- **KinSelect** ([resources/js/components/design-system/KinSelect.vue](resources/js/components/design-system/KinSelect.vue)) — native `<select>` wrapped in KinInput's borderless inset look. Supports placeholder, helper, error, required, disabled, sizes (sm / md / lg), and grouped options via `optgroup`. Uses native `<select>` under the hood so keyboard navigation, screen-reader semantics, mobile native picker, and form integration work out of the box; only the chevron is custom (overlaid `ChevronDownIcon` with `pointer-events-none`). Light: sunken-fill + accent-lavender ring on focus. Dark: raised-overlay so the field doesn't disappear into the page.
- **Design-system page** at `/design-system/select` ([resources/js/views/design-system/pages/primitives/SelectPage.vue](resources/js/views/design-system/pages/primitives/SelectPage.vue)) covers default states (empty + placeholder, filled, error, disabled, readonly), label + helper, three sizes, and grouped (`optgroup`) options.
- **Registry** ([resources/js/views/design-system/registry.js](resources/js/views/design-system/registry.js)) — slot 1.8 added under Tier 1 — Primitives, marked `chosen: true`.

### KinChip — `customColor` escape-hatch

- ([resources/js/components/design-system/KinChip.vue](resources/js/components/design-system/KinChip.vue)) — new `customColor` prop accepts any CSS color string. When set: active state fills with that color + white text + matching border via inline style; inactive state shows a small dot of that color + neutral surface bg. Use sparingly — accent families are still preferred. Unblocks tag pills, calendar legend dots, and anywhere else a per-row brand color outside the lavender / peach / mint / sun palette is needed.

### Calendar — KinMonthGrid migration

- **CalendarView.vue** ([resources/js/views/calendar/CalendarView.vue](resources/js/views/calendar/CalendarView.vue)) — month view fully on `KinMonthGrid` (density="dots", maxDots=4). Removed the bespoke 7-column grid + inline event-title pills. New adapters wired in:
  - `monthCells` — 42-cell array shaped `{ day, month: 'current'|'leading'|'trailing' }`.
  - `monthEventsMap` — `{ [day]: [accent, accent, ...] }`. Source-based mapping: `task → 'sun'`, `manual → 'lavender'`, calendar connections cycle through `[peach, mint, lavender, sun]` by index so each calendar gets a stable accent regardless of its raw hex.
  - `monthEventLabels` — passed for hover/aria context (KinMonthGrid uses these in pills mode; harmless in dots mode).
  - `todayInCurrentMonth` — number-only for the today-circle.
  - `onMonthDaySelect(day)` — KinMonthGrid emits `select(day)`. If the day has a manual event, opens it for editing; otherwise opens the create modal pre-filled with that date.
  - Dropped `getEventsForDay` and `eventStyle` (no longer referenced).
- **Trade-off:** inline event titles are gone (dots only). Per Greg: "go with KinMonthGrid; if it isn't good we can update it later." Mobile spacing benefits kick in immediately — KinMonthGrid bakes in `min-h-[42px] md:min-h-[52px]` instead of the old `min-h-24` desktop-first cell.
- **Calendar legend** restyled with `KinChip` — `customColor` for connection chips, accent colors for "Family Events" (lavender) and "Tasks" (sun). All chips are `disabled` since they're informational, not interactive.

### Tasks — KinModalSheet + KinSelect + KinChip color

- **TaskDetailPanel.vue** ([resources/js/components/tasks/TaskDetailPanel.vue](resources/js/components/tasks/TaskDetailPanel.vue)) — `SlidePanel` → `KinModalSheet` (centered modal on desktop, bottom sheet on mobile). `#footer` slot → `#actions`. Tag toggle buttons now `KinChip` with `customColor` + `:active` for selection. Assignee `<select>` → `KinSelect` with `assigneeOptions` (Unassigned + assignable members). Recurrence-preset `<select>` → `KinSelect` with the existing 11 preset options. The `text-sand-600` "Unsaved changes" indicator is now `text-ink-tertiary` (sand wasn't a Kin token).
- **TasksView.vue** ([resources/js/views/tasks/TasksView.vue](resources/js/views/tasks/TasksView.vue)) — tag filter row's "All" button + per-tag chips now `KinChip` (neutral / customColor) with `:active` toggling. Bare `<button>` markup gone. The Manage-tags cog stays bespoke (no Kin equivalent for inline tools like that).
- **TaskItem.vue** — the always-displayed colored tag labels stayed as inline-style spans on purpose; `KinChip`'s `customColor` is for filter chips (pick / unpick) and didn't fit the always-shown label use case.

Verified in preview: `/calendar` month view shows the Kin grid with accent dots beneath each day number and the today-circle filled lavender; legend chips render with their connection colors. `/tasks` list shows KinChip filter row with custom-color dots/fills; clicking a task opens the new centered KinModalSheet with KinSelect dropdowns for assignee + recurrence. `/design-system/select` demo page shows KinSelect across all states. No console errors after fresh reload.

### Notes

- KinChip `customColor` does not currently support hover states differently per-color (just relies on the global `hover:brightness-95` filter rule). If specific hover treatment is needed for branded chips in future, that's a small extension.
- KinMonthGrid's day-of-week header row sits flush with cells (no separate background). If the visual separation feels too subtle, we can wrap the grid in a `KinFlatCard` instead of the current padded surface div.
- Past-day dimming is gone in the new month view (KinMonthGrid doesn't expose an `isPast` prop). If wanted, this would be a small API extension on KinMonthGrid.

## 2026-04-26 — Tier 6.2 + 6.3: Calendar and Tasks refactored onto Kin design-system

Two more views moved onto the Kin component library and tokens. Both are **Phase 1** sweeps — token consistency + the obvious component swaps. Phase 2 questions (KinMonthGrid full migration, KinModalSheet vs SlidePanel) are listed under Caveats so the next session can pick them up cleanly.

### Tier 6.2 — Calendar

- **CalendarView.vue** ([resources/js/views/calendar/CalendarView.vue](resources/js/views/calendar/CalendarView.vue)) — three swaps. (1) View-mode selector (Month/Week/Day) → `KinTabPillGroup` variant=tinted with `v-model:active-key`; (2) all calendar buttons (Add Event, prev/next, Today) → `KinButton` variants — primary, ghost icon-only, secondary; (3) page buttons + grid + legend rebuilt on surface/ink/border tokens. **Kept bespoke**: the month-cell event rows (the inline title pills inside each day) — `KinMonthGrid` is dots/pills only, swapping would lose at-a-glance event titles. Day numbers, today highlight, past-day dimming, and source legend now use `accent-lavender-bold` and `accent-sun-bold` tokens.
- **TimeGrid.vue** ([resources/js/components/calendar/TimeGrid.vue](resources/js/components/calendar/TimeGrid.vue)) — token-only restyle. All-day pills, hour grid lines, hour labels, current-time line, week-view day headers, today circle, past-day dimming all moved to surface-raised / surface-sunken / border-subtle / ink-tertiary / accent-lavender-bold. The overlap-positioning algorithm and event block `:style` (per-event hex via `getEventColor`) are unchanged.

### Tier 6.3 — Tasks

- **TasksView.vue** ([resources/js/views/tasks/TasksView.vue](resources/js/views/tasks/TasksView.vue)) — token sweep + two swaps. Tag Manager modal: `BaseModal` → `KinModalSheet` (with `:model-value` shim against the existing `show`/`@close` API); Add-tag submit button → `KinButton` primary. Empty state → `KinEmptyState` mint accent. Tag filter chips kept bespoke (dynamic per-tag hex colors don't fit `KinChip`'s accent-prop API).
- **TaskCheckbox.vue** ([resources/js/components/tasks/TaskCheckbox.vue](resources/js/components/tasks/TaskCheckbox.vue)) — token swap only. Priority-driven border colors (red/orange/lavender) preserved as domain logic.
- **TaskItem.vue** ([resources/js/components/tasks/TaskItem.vue](resources/js/components/tasks/TaskItem.vue)) — token swap only. Tag pills kept bespoke (dynamic hex).
- **TaskQuickAdd.vue** ([resources/js/components/tasks/TaskQuickAdd.vue](resources/js/components/tasks/TaskQuickAdd.vue)) — token sweep + Cancel / Add Task → `KinButton` (ghost / primary, `:disabled` preserved).
- **TaskDetailPanel.vue** ([resources/js/components/tasks/TaskDetailPanel.vue](resources/js/components/tasks/TaskDetailPanel.vue)) — biggest set of swaps. `KinInput` for title / due date / points / custom RRULE / recurrence-end. `KinTextarea` for description. `KinSwitch` for "Open to Anyone" (lavender) and "Completed" (mint). `KinSegmentedFilter` for the priority Low/Medium/High picker. `KinButton` for Delete (danger), Cancel (ghost), Save (primary, with `:disabled` + saving label). Removed orphaned `FlagIcon` import + unused `prioritySelectedClass` helper. **Kept bespoke**: assignee `<select>` and recurrence-preset `<select>` (no Kin select component yet); the slide-out wrapper itself stays `SlidePanel` — see Phase 2 caveat.

### Verified in preview

Calendar: Month + Week views render cleanly, today's-circle in lavender, past days dimmed, legend in surface card. Tasks: list view, Tag Manager modal opens as a centered KinModalSheet (440px desktop), task detail panel opens with all Kin form fields. No new console errors after fresh reload.

### Phase 2 caveats (left for review)

- **Calendar month grid:** the inline event-title pills inside each cell are bespoke. Swapping to `KinMonthGrid` (dots or pills density) would gain mobile-friendly density tokens but lose inline titles at-a-glance. Open question for review.
- **Calendar TimeGrid:** still bespoke. The overlap-positioning algorithm + hour-row math don't have a Kin equivalent. A future `KinWeekTimeGrid` / `KinDayTimeGrid` would let us drop this; today only the styling is Kin-aligned.
- **Calendar legend:** bespoke. Could become a `KinChip` row with an accent prop, but the connection-color pills use dynamic per-calendar hex (same problem as task tags).
- **TaskDetailPanel** still uses `SlidePanel` as the wrapper. The inventory recommended swapping to `KinModalSheet`, but that would change the slide-from-right desktop UX to a centered modal — worth a design call before changing. The contents inside are fully Kin-styled regardless.
- **Task tag pills + Calendar legend dots** both want a "dynamic-hex chip" pattern that `KinChip` doesn't support today (it uses accent-family props). Consider adding `color` (CSS color string) as an escape-hatch prop on `KinChip` in a future iteration.
- **Task `<select>` fields** (assignee, recurrence preset) are bespoke. No `KinSelect` exists yet.

Tier 6.2 + 6.3 complete (Phase 1).

## 2026-04-26 — Tier 6.0: App shell refactored onto Kin nav components

Sidebar, TopBar, and the BottomNav fragment-root warning, all in one pass — so every Tier 6.x view sits inside a Kin-consistent chrome.

- **Sidebar.vue** ([resources/js/components/layout/Sidebar.vue](resources/js/components/layout/Sidebar.vue)) — replaced the dark prussian sidebar with `KinSidebar`. Brand area uses `#brand-icon` slot for the logo image (preserves the easter-egg click handler); brand text is the family name. User footer is rendered via `#user` slot — `KinAvatar` for the user, name + role link to `/settings`, and Sign Out button. `v-model:collapsed` persisted to `localStorage` (same key as before, `kinhold-sidebar-collapsed`). Active nav item is computed by longest-matching path so `/points/rewards` correctly highlights "Rewards" rather than "Points". The "FAMILY HUB" subtitle is dropped — design-system convention is single-line brand. Visual change is intentional: sidebar now matches the light Kin surface tokens, not the legacy navy.
- **TopBar.vue** ([resources/js/components/layout/TopBar.vue](resources/js/components/layout/TopBar.vue)) — left as a thin utility strip (didn't force onto `KinTopNav`, which is a different pattern with built-in nav pills). The four `UserAvatar`s + "+N" overflow chip now use `KinAvatar` with `color="lavender"` so initials render against the Kin soft-lavender background with proper ring offset. Dark-mode toggle restyled with `text-ink-tertiary` + `bg-surface-sunken` hover.
- **BottomNav.vue** ([resources/js/components/layout/BottomNav.vue](resources/js/components/layout/BottomNav.vue)) — kept bespoke (its 5-slot grouped navigation with two popover groups doesn't map onto `KinBottomNav`'s 4-item + center-FAB convention). Wrapped the template in a single root `<div>` so the parent's `class="md:hidden"` inherits cleanly — eliminates the ~30-per-render `[Vue warn] Extraneous non-props attributes` spam that was filling the console. Moved the `md:hidden` class from the inner `<nav>` up to the wrapper so the Teleport-anchored backdrop is also hidden on desktop.

Verified in preview: light-mode dashboard renders with the new KinSidebar, lavender pill on the active Dashboard item, collapse toggle, KinAvatar user footer, and KinAvatar family row in the top bar. Mobile (375×812) shows the existing mobile header + bespoke BottomNav with no Vue warnings on a fresh reload.

Tier 6.0 complete.

## 2026-04-26 — Tier 6.1: Dashboard refactored onto Kin design-system

First view-level integration of the new Kin* component library (branch `redesign/visual-overhaul`). Refactored the Dashboard top-to-bottom; behavior unchanged, visuals shifted to design-system tokens.

- **DashboardView.vue** — edit toggle, loading skeleton grid, and "no widgets yet" empty state now use `KinButton`, `KinSkeleton`, `KinEmptyState`. Sortable.js drag wrapper preserved as bespoke (correctly).
- **DashboardToolbar.vue** — three buttons (Add / Cancel / Save) replaced with `KinButton` variants (secondary / ghost / primary with `loading`). Sticky bar restyled with token classes (`bg-surface-raised`, `border-border-subtle`, `text-ink-primary`).
- **DashboardWidget.vue** — `BaseCard shadow="lg"` replaced with `KinFlatCard padding="sm"`; remove button is now an icon-only `KinButton ghost`; Suspense fallback uses `KinSkeleton`. Drag handle and edit-mode dashed ring kept bespoke. Color classes mapped from `lavender-*`/`prussian-*` to surface/ink tokens.
- **WidgetPickerModal.vue** — replaced `BaseModal` with `KinModalSheet` (responsive bottom-sheet on mobile, centered modal on desktop). Title field uses `KinInput`; "Filter by Tags", "Due Within", and "Size" sections use `KinFormGroup` wrappers; loading-pill skeletons use `KinSkeleton`; Cancel/Add to Dashboard moved to the modal's `#actions` slot as `KinButton`s. Tag color-pills kept bespoke (dynamic backgroundColor from API).
- **12 widget files swept** — `KinSkeleton` replaces every inline `animate-pulse` placeholder; `KinEmptyState` replaces every "nothing yet" block in ActivityFeed, Badges, FamilyTasks, FilteredTasks, Leaderboard, MyTasks, TodaysSchedule. Empty-state accent colors picked per intent (lavender default; mint for "all caught up", sun for trophy, peach for warm/celebratory contexts where appropriate). Task rows, podium, color-coded event accents, and "View All" RouterLinks intentionally left bespoke.
- Verified in preview: dashboard renders cleanly in light mode, edit toolbar appears with three Kin buttons, dashed ring + drag handle + size toggle on each widget, Add Widget picker opens as a 440px desktop modal with the title "Add Widget" and the categorized widget grid. No new console errors after fresh reload.

Tier 6.1 complete. Next view (per roadmap): Calendar.

## 2026-04-26 — Session 38: Root Directory Cleanup, Personal Context Split, Upsun Integration Repair

### What Was Done

**Root directory decluttered (PR #172)**
- Reduced visible root entries by relocating files to conventional homes:
  - `setup.sh` → `scripts/setup-dev.sh` (developer/full-stack Docker flow). Now exports `COMPOSE_FILE` so the script keeps working with the relocated compose file.
  - `docker-compose.yml` (dev) → `docker/docker-compose.dev.yml`. Self-host pair (`docker-compose.simple.yml` + `setup-simple.sh` + `.env.docker-simple`) and `Dockerfile` stayed at root for one-command self-hosting.
  - `PRINCIPLES.md` → `docs/PRINCIPLES.md`.
  - `hooks/pre-commit` → `scripts/hooks/pre-commit`. `composer.json` post-install hook now points `core.hooksPath` at `scripts/hooks`.
  - `playbooks/` → `resources/playbooks/` (Laravel-conventional). MCP tools `ListPlaybooks` and `GetPlaybook` updated `base_path()` → `resource_path()`.
- All references chased: README, SELF-HOSTING, CONTRIBUTING, CLAUDE.md, the moved scripts.
- Pre-commit hook hardened to find PHP across macOS (Homebrew), Linux, and Windows (auto-discovers winget's `PHP.PHP.X.Y_*` package dir, picks the highest version).

**Personal context split out of CLAUDE.md**
- New `CLAUDE.local.md` (gitignored) holds owner identity, Upsun project ID, instance specifics, ADHD/collaboration notes — anything that doesn't belong in a public OSS repo.
- Public `CLAUDE.md` is now contributor-/AI-agnostic: removed the Project Owner section, hardcoded Upsun project ID, "Greg manages…" lines, and de-personalized session-rule wording. `CLAUDE.local.md` is referenced from the top of the public file so any Claude session reads both on this checkout.
- `.gitignore` updated to exclude `CLAUDE.local.md`.

**Upsun GitHub integration repair (production fix, not in PR)**
- After the `q32hub → kinhold` rebrand, the Upsun GitHub integration kept its old repository pointer. GitHub redirects (`q32hub` → `kinhold`) caused Upsun's HTTP client to strip auth on redirect, returning 401 on every fetch. Webhooks still delivered successfully (200 OK) so the integration looked healthy from the outside; only `upsun integration:activity:list` exposed the per-fetch failures. Result: PRs got CI but no preview environments since the rename.
- Diagnosed via webhook delivery history (200 OK, but no Upsun status checks on PR #172 vs PR #171 having them) → integration:list (revealed `repository: gregqualls/q32hub`) → integration:activity:list (revealed every fetch 401-ing on the redirected URL).
- Fix: deleted the stale integration via CLI, re-added via Upsun console (clean GitHub App OAuth flow vs CLI's PAT-only path). New integration `zfotth2rn333o` correctly tracks `gregqualls/kinhold`. Preview env for PR #172 came up immediately.
- Worth flagging on other Upsun projects after any GitHub repo rename — the failure mode is silent.

### Files Changed

- Moved (10): `setup.sh`, `docker-compose.yml`, `PRINCIPLES.md`, `hooks/pre-commit`, `playbooks/{dashboard,vault}/*.md`
- Modified: `.gitignore`, `CLAUDE.md`, `CONTRIBUTING.md`, `README.md`, `SELF-HOSTING.md`, `composer.json`, `app/Mcp/Tools/{ListPlaybooks,GetPlaybook}.php`, `scripts/hooks/pre-commit` (PATH portability), `scripts/setup-dev.sh` (COMPOSE_FILE export)
- New (gitignored): `CLAUDE.local.md`

---

## 2026-04-17 — Session 37: Tag Scopes, Meal Plan Shopping Flow, Responsive Grid, Mobile Nav Redesign

### What Was Done

**Tag system overhaul (data model)**
- New `tags.scope` enum column (`task` | `food`) backed by `App\Enums\TagScope`. Added composite index `(family_id, scope)`. Migration backfills existing tags by recipe/restaurant attachment + name (`Breakfast/Lunch/Dinner/Dessert/Snack` → food).
- New `restaurant_tag` pivot table + `RestaurantTag` Eloquent pivot model. `Restaurant->tags()` and `Tag->restaurants()` relations.
- `TagController` accepts `?scope=` query filter and `scope` param on create. `TagResource` exposes `scope` + `restaurants_count`.
- All Pinia stores fetch with the right scope: `tasks.js` → `?scope=task`, `recipes.js` + `restaurants.js` → `?scope=food`. Dashboard widgets that filter by tags now request task-scoped tags.
- Onboarding `TagsStep` and demo seeder set scope explicitly on creation.
- Removed legacy `recipes_count > 0 || tasks_count == 0` workarounds in RecipeForm/RecipesTab/RestaurantsTab — replaced with the server-side scope filter.
- `ManageTags` MCP tool updated: `scope` filter on list, scope param on create (defaults to task), surfaces `restaurant_count` in list output.

**Restaurant tag UI**
- `RestaurantsTab` gained a tag filter chip row matching the recipes tab, plus tag chips inline on cards.
- New shared `TagPicker.vue` component with toggleable chips + inline "Add tag" creator. Used in restaurant detail panel + both add modals (manual/import).
- `RestaurantController` accepts/returns `tag_ids`; supports `?tag=<uuid>` filter on index. Tag IDs validated against the user's family at the request level (defense in depth + cleaner 422s).

**Meal-plan shopping flow (preview before adding)**
- New `GET /meal-plans/{plan}/shopping-preview?days=N&shopping_list_id=…` returns recipe entries in range with their ingredients, each annotated with `already_on_list: bool` against the chosen list.
- New `POST /meal-plans/{plan}/add-to-shopping-list` accepts `{ selections: [{ entry_id, ingredient_ids? }], shopping_list_id? }`.
- New `MealPlanShoppingModal.vue` opened by the cart icon: days-ahead pill picker (Today/3/5/7/14/30), shopping-list dropdown with inline "+ New list" creator, per-entry collapsible ingredient pickers, footer total + global Select/Deselect all.
- Shared `RecipeIngredientPicker.vue` now drives both the Shopping tab single-recipe flow and the meal-plan modal (DRY). Already-on-list ingredients render strikethrough with an "On list" pill and are unchecked by default.
- `ShoppingTab` annotates the recipe-picker against the active list's items so duplicates aren't auto-selected.
- `MealPlanService` gained `entriesWithIngredientsInRange()`, `existingShoppingItemNames()`, and `addSelectionsToShoppingList()` reusing the existing `ShoppingListService::addRecipeIngredients` (so dedup, attribution, and quantity aggregation match the Shopping tab path).

**Responsive meal-plan week grid**
- `MealWeekGrid` measures its container with `ResizeObserver` and shows `floor((width - 120) / 140)` day columns clamped to 1–7 — no more horizontal scroll/clipping. When fewer than 7 fit, intra-week pagination chevrons appear; today auto-anchors into view on resize/week-change.
- Parent overflow changed from `overflow-x-auto` → `overflow-x-hidden`.

**Past-day fading (Google Calendar–style)**
- `CalendarView` month grid, `TimeGrid` (week/day), `MealWeekGrid` (desktop), and `MealDaySection` (mobile) all dim past days to ~55% opacity with darker dark-mode backgrounds and muted day labels. Hover restores full opacity.

**Mobile nav redesign**
- `BottomNav` rebuilt with grouped slots. Five primary slots: Home / Schedule (Calendar+Tasks) / Meals (Meals+Shopping) / Points / Assistant.
- Tapping a grouped slot opens a small popover above the bar with its children (smooth fade+slide, blurred backdrop). Active group glows wisteria when inside any child route. Closes on route change, click-outside, or **Escape**.
- "Meals" group uses Phosphor's fork-and-knife icon (regular/fill weights for inactive/active).
- Fixed: `md:hidden` is now baked into the `<nav>` root so it stays mobile-only regardless of attribute inheritance with multi-root components.
- Sidebar + FoodView heading + first food tab renamed: `Food` → `Meals`, first tab `Meals` → `Plans`.

**Mobile meals scroll**
- `MealsTab` mobile section now opens at today by default (was scrolling past prior days). New "Show earlier days" pill at top exposes the previous 7 days per tap (loads the prior week's plan if needed) while preserving scroll position.

**Cuisine → tags cleanup**
- Dropped the `cuisine` string column on `restaurants`. Migration backfills existing values as food-scoped tags per linked family, then drops the column.
- `RestaurantImportService.extractFromUrl` now returns `cuisines: []` (comma/semicolon-split). Import auto-attaches them as food tags via a new `attachCuisineTags()` helper.
- Preview flow auto-resolves scraped cuisines to tag IDs client-side so they show up as pre-selected (deselectable) chips in the form.
- Restaurant model/Request/Controller/Resource scrubbed of cuisine. Search matches name/address/tags.
- Demo seeder now attaches `Italian`/`Mexican` food tags instead of setting cuisine.

**ESLint cleanup**
- Zero errors, zero warnings. Deleted `MealDayCard.vue` + `MealDayColumn.vue` (unimported dead code from pre-grid layout). Dropped unused `emit` const assignments + dev `console.warn` from drag handler.

**Polish + DX**
- DemoModal dark hover bug fixed (`prussian-750` → wisteria-tinted).
- `scripts/dev-laravel.sh` added — idempotent SQLite create + migrate + seed-if-needed wrapper for `php artisan serve`. Wired into `.claude/launch.json`.
- DemoModal/login flow exposed: launch.json now uses the wrapper so a fresh checkout self-bootstraps.

### Files Created
- `app/Enums/TagScope.php`
- `app/Models/RestaurantTag.php`
- `database/migrations/2026_04_17_120000_create_restaurant_tag_table.php`
- `database/migrations/2026_04_17_130000_add_scope_to_tags_table.php`
- `resources/js/components/food/TagPicker.vue`
- `resources/js/components/food/RecipeIngredientPicker.vue`
- `resources/js/components/meals/MealPlanShoppingModal.vue`
- `scripts/dev-laravel.sh`
- `.claude/launch.json`

### Known follow-ups (deferred)
- **`ManageRestaurants` MCP tool** — restaurants are now tagged + filterable but not exposed via MCP. Belongs in #155 scope.
- **Tests** for the new endpoints (`shopping-preview`, `add-to-shopping-list`, restaurant tag attach, `?scope=` filter) — should land alongside #155's MCP tool tests.
- **Demo seed** of food-tag attachments on recipes/restaurants — covered by #155's `DemoFoodSeeder`.
- **Full `Food` → `Meals` rename** (route `/food`, module key `food`, `/api/v1/recipes` etc.) — bigger refactor, deferred. Nav labels are renamed; URLs/keys still say `food`.

---

## 2026-04-17 — Session 36: Meal Planner UX Overhaul + Restaurant Import

### What Was Done
- **Phase 1 — Drag-and-drop fix (critical)** — `chosen-class` had space-separated classes which broke `DOMTokenList.add()` causing the "can drag but won't drop, stuck" state. Replaced with single CSS class `meal-drag-chosen`. Also fixed `evt.item` entryId extraction to query descendants (wrapper vs card root). Removed `force-fallback` (blocked native drop events). Watcher now mutates `localEntries` in place so vue-draggable-plus keeps its array refs.
- **Phase 2 — Images + cook avatars on meal cards** — New `image_url` column on `restaurants` table. MealPlanEntryResource adds convenience `image_url` resolving recipe `image_path` → `/storage/...` or restaurant URL. MealEntryCard redesigned with 16:9 thumbnail, overlapping `UserAvatar` stack for assigned cooks, map-pin overlay for restaurant entries.
- **Phase 3 — Restaurant import from any URL** — Rewrote `RestaurantImportService` with full HTTP scraping: follows redirects, parses JSON-LD (`Restaurant`/`LocalBusiness`/`@graph`), falls back through OG tags → `og:image:secure_url` → Twitter Card → embedded photo URLs → HTML title → domain name. Handles both Google Maps and restaurant websites. Generic title filter (strips "Home", "Welcome", "Google Maps" etc). `tel:` link + structured-data regex for phones. Downloads scraped images locally to `storage/app/public/restaurants/`.
- **Phase 4 — Preview-then-edit-then-save import flow** — Matches the recipe import UX. New `POST /restaurants/import?preview=1` endpoint returns extracted data without saving. Form populates with extracted values, user edits, clicks Save. Green "Details extracted!" banner.
- **Phase 5 — Layout overhaul** — New `MealWeekGrid.vue` for desktop (transposed: slot rows × day columns, sticky slot labels, today highlighted). New `MealDaySection.vue` for mobile (continuous scroll from today, infinite-loads next weeks, auto-scroll-to-today on mount). Retired `MealDayColumn` and `MealDayCard` from MealsTab.
- **Shared `FoodCard.vue` component (DRY)** — Used by recipes, restaurants, and mirrored visually by meal entries. Same 4:3 image, favorite heart overlay, meta row, tag pills.
- **Shared `PhotoUpload.vue` component (DRY)** — Used by RecipeForm and RestaurantsTab. Click-to-upload + drag-over + preview + keyboard accessible (role=button, tabindex, enter/space).
- **Restaurant editing** — Controller `update()` now handles core fields (name, cuisine, address, phone, URLs, image). Detail panel fully editable. `StoreRestaurantRequest` validates `image_url`.
- **Preset icons** — MealEntryPicker now uses `IconRenderer` (shared with rewards). Expanded `presetIcons.js` with 13 food icons: `utensils-crossed`, `store`, `package`, `truck`, `fork-knife`, `bowl-food`, `coffee`, `hamburger`, `egg`, `carrot`, `fish`, `cooking-pot`, `pepper`, `apple`.
- **Restaurant upload endpoint** — `POST /api/v1/restaurants/upload-image` with file-type/size validation.
- **SSL cert globally fixed** — Downloaded Mozilla CA bundle to `C:\php-8.4.20\extras\ssl\cacert.pem` and configured `curl.cainfo` + `openssl.cafile` in `php.ini`. Recipe imports on Windows dev now work.
- **Better import error messages** — Recipe service distinguishes 402/403/429 (site blocks scrapers) from 404 (not found), recommends "From Photo" as fallback.
- **Brand guide compliance** — Removed emoji from MealEntryPicker source tabs (`🍳 Recipe` → `Recipe`).

### Security hardening (from `/review`)
- **SSRF protection** — `RestaurantImportService::fetchWithRedirects` and `downloadAndStoreImage` now validate scheme (http/https only), resolve DNS, verify public IP range, pin DNS via Guzzle `resolve` option, and manually walk redirects (re-validating each hop). Matches the existing pattern in `RecipeImportService`.
- **URL scheme validation** — `POST /restaurants/import` now enforces `url:http,https` (was accepting `file://`, `gopher://` etc).
- **Gitignore** — Added runtime uploads (`/storage/app/public/recipes/`, `/restaurants/`, `/avatars/`), dev SQLite, and dev-artifact patterns. Untracked `database/database.sqlite` from the repo.

### Accessibility
- `FoodCard` image now falls back to placeholder on load error (not just missing URL).
- `PhotoUpload` clickable div has `role=button`, `tabindex`, keyboard handlers, focus ring, aria-label.
- `MealEntryCard` icon-only buttons (delete, maps link) have descriptive aria-labels.

### Issues filed for future sessions
- **#167** — Explore scraping options for JS-rendered sites (Google Maps) — headless browser, Places API, AI extraction, browser extension.
- **#168** — Explore import options for bot-blocked recipe sites (allrecipes, seriouseats) — same menu of options.

### Files Created
- `database/migrations/2026_04_16_200407_add_image_url_to_restaurants_table.php`
- `resources/js/components/food/FoodCard.vue`
- `resources/js/components/food/PhotoUpload.vue`
- `resources/js/components/meals/MealWeekGrid.vue`
- `resources/js/components/meals/MealDaySection.vue`

### Files Modified
Backend: `RestaurantController`, `StoreRestaurantRequest`, `RestaurantResource`, `MealPlanEntryResource`, `Restaurant` model, `RecipeImportService` (error messages), `RestaurantImportService` (full rewrite), `routes/api.php`, `phpstan-baseline.neon`.
Frontend: `MealEntryCard`, `MealEntryPicker`, `RecipeCard`, `RecipeForm`, `RestaurantsTab`, `MealsTab`, `meals` + `restaurants` stores, `presetIcons`, `app.css`.

---

## 2026-04-16 — Session 35 (cont.): Meal Planner UX Polish

### What Was Done
- **Brand guide compliance** — Replaced emoji slot labels (🌅☀️🌙🍎) with Heroicons (`SunIcon`, `CloudIcon`, `MoonIcon`, `CakeIcon`). Updated all colors to brand hex values.
- **Configurable meal slots** — Added `meal_slots` family setting. Settings > Food now has toggle-chip UI (Breakfast/Lunch/Dinner/Snack). Components filter slots reactively. Hidden slots preserve their data.
- **Improved desktop grid layout** — Columns now have `minmax(160px, 1fr)` preventing title truncation. Horizontal scroll fallback on narrower screens.
- **CI fix** — Updated `phpstan-baseline.neon` stale `family_avg_rating` pattern to `family_average_rating`.
- **Post-mortem + feedback memories saved** — Incremental testing rules and brand guide compliance saved to session memory.
- **PR #166** — All fixes committed and pushed to `feature/154-meal-plan-frontend`.

---

## 2026-04-16 — Session 35: Food Step 7 — Meal Plan Frontend (Issue #154)

### What Was Done
- **Weekly meal planner UI (issue #154)** — Full frontend for the meal planning module shipped
- **Root bug fix:** `vue-draggable-plus` uses the default slot with `v-for`, NOT a `#item` named slot (as in the older `vuedraggable`). Changed both `MealDayColumn` and `MealDayCard` — this was why all 19 seeded entries were invisible
- **Additional backend fixes:**
  - `MealPlanService::getOrCreatePlan()` — rewrote `firstOrCreate` to explicit find-then-create (SQLite throws raw `PDOException` not `UniqueConstraintViolationException`); used `whereDate()` for date comparison (SQLite stores date-cast as datetime string)
  - `RestaurantController` — fixed `family_avg_rating` → `family_average_rating` in `index()` and `show()` to match `RestaurantResource` and frontend
  - All Pinia store response keys fixed: `response.data.data` → named keys (`restaurants`, `restaurant`, `meal_plan`, `entry`, `presets`)
- **3 tabs in FoodView** — Recipes | Restaurants | Meals all wired up
- **Restaurants tab** — Card grid with search, favorite heart, star ratings, SlidePanel details, Add/Import modals
- **Meals tab** — 7-column weekly grid (desktop), collapsible day cards (mobile), week nav prev/next/"This Week", entry cards with type icons, drag-and-drop via vue-draggable-plus
- **MealEntryPicker** — SlidePanel with 4 source tabs (Recipe/Restaurant/Preset/Custom), cook assignment, servings, notes
- **Settings > Food** — "Week Starts On" select (Monday/Sunday), wired to `PUT /settings` + `Family::getWeekStartDay()`
- **All 125 tests passing**

### Files Created
- `resources/js/stores/meals.js`
- `resources/js/stores/restaurants.js`
- `resources/js/views/food/MealsTab.vue`
- `resources/js/views/food/RestaurantsTab.vue`
- `resources/js/components/meals/MealEntryCard.vue`
- `resources/js/components/meals/MealDayColumn.vue`
- `resources/js/components/meals/MealDayCard.vue`
- `resources/js/components/meals/MealEntryPicker.vue`

### Files Modified
- `resources/js/views/food/FoodView.vue` — 3 tabs (Recipes/Restaurants/Meals)
- `resources/js/views/settings/SettingsView.vue` — Food section with week_start_day
- `app/Models/Family.php` — `getWeekStartDay()` method
- `app/Http/Controllers/Api/V1/SettingsController.php` — week_start_day in GET/PUT
- `app/Services/MealPlanService.php` — SQLite-safe getOrCreatePlan + whereDate fix
- `app/Http/Controllers/Api/V1/RestaurantController.php` — family_average_rating fix
- `package.json` — added vue-draggable-plus

### Files Deleted
- `resources/js/views/food/MealsPlaceholder.vue`

---

## 2026-04-15 — Session 34: Food Step 6 — Meal Plan Backend (PR #165)

### What Was Done
- **Meal plan backend (issue #153)** — Full "live pipeline" backend: 2 new migrations (source morphTo on tasks + 5 meal plan tables), 5 new models, `MealPlanService`, `RestaurantImportService`, `MealPlanPolicy`, 5 form requests, 5 API resources, 2 controllers, demo seeder
- **Live pipeline:** Recipe entries auto-populate the shopping list via `ShoppingListService`; cook assignments cascade into `Task` records with `source_type/source_id` morph columns; updateEntry is diff-based (only re-syncs what changed)
- **Restaurant management:** Global `restaurants` table + `family_restaurants` pivot; `RestaurantImportService` parses Google Maps URLs; per-family notes, favorites, and ratings
- **MealPlanPolicy:** Scoped to family; parent-only for destructive/preset actions; registered non-standard binding in AppServiceProvider (`MealPlanEntry → MealPlanPolicy`)
- **Review blockers fixed:** Family-scoped validation on all source exists rules, `authorize()` on `rate()`, N+1 eliminated in restaurant index
- **Version held at 1.2.1** — will bump to 1.3.0 when full food module (backend + frontend) ships
- **PR #165 open** — CI running, preview environment deploying

### Files Created
- `database/migrations/2026_04_15_000001_add_source_to_tasks_table.php`
- `database/migrations/2026_04_15_000002_create_meal_plan_tables.php`
- `app/Models/{MealPlan,MealPlanEntry,MealPreset,Restaurant,FamilyRestaurant}.php`
- `app/Services/{MealPlanService,RestaurantImportService}.php`
- `app/Policies/MealPlanPolicy.php`
- `app/Http/Requests/MealPlan/{StoreMealPlanRequest,StoreMealPlanEntryRequest,UpdateMealPlanEntryRequest,StoreRestaurantRequest,StoreMealPresetRequest}.php`
- `app/Http/Resources/{MealPlanResource,MealPlanEntryResource,MealPresetResource,RestaurantResource,FamilyRestaurantResource}.php`
- `app/Http/Controllers/Api/V1/{MealPlanController,RestaurantController}.php`
- `database/seeders/DemoMealPlanSeeder.php`

### Files Modified
- `app/Models/Task.php` — added `source_type`, `source_id` to `$fillable` + `sourceable()` morphTo
- `app/Models/Family.php` — added `mealPlans()`, `mealPresets()`, `restaurants()` relationships
- `app/Providers/AppServiceProvider.php` — registered `MealPlanEntry → MealPlanPolicy`
- `database/seeders/DatabaseSeeder.php` — added `DemoMealPlanSeeder`
- `routes/api.php` — added meal plan + restaurant routes under `module:food`
- `phpstan-baseline.neon` — regenerated after new service/model additions

### Test Results
- 125 tests, 346 assertions — PASS
- Pint: FAIL (line_ending across entire codebase — pre-existing Windows CRLF issue, not introduced this session)
- Larastan: 0 errors
- ESLint: PASS
- Vite build: PASS

---

## 2026-04-14 — Session 33: Recipe Ingredient Bug Fixes (v1.2.1)

### What Was Done
- **Bug #160 fixed — ingredient parsing:** JSON-LD `recipeIngredient` strings (e.g. "2 cups flour") are now properly parsed into structured `quantity`/`unit`/`name`/`preparation` fields instead of dumping the whole string into `name`. Implemented `parseIngredientString()` with support for fractions, mixed numbers, unicode fractions, and a broad unit list. LLM prompts tightened with explicit CRITICAL rules and counter-examples. Added post-processing step in `parseLlmResponse()` that re-parses any ingredient where the LLM put the full string in `name` with no quantity/unit.
- **Bug #161 fixed — fractional quantities:** Recipe ingredient quantities like `1/2`, `3/4`, `1 1/2`, `½`, `¾` no longer fail validation. Created `FractionalQuantity` rule with `parseToFloat()` static helper. Both `StoreRecipeRequest` and `UpdateRecipeRequest` use `prepareForValidation()` to normalise fractions to floats before the `numeric` rule runs. Frontend `RecipeForm.vue` converts fractions to floats on submit and displays stored decimals as human-readable fractions (0.5 → "1/2") when loading a recipe.
- **Version bumped to 1.2.1**
- **Issue #66 closed** — Meal planning marked complete (shipped in Steps 4 & 5).

### Files Created
- `app/Rules/FractionalQuantity.php` — validation rule + `parseToFloat()` + `floatToFraction()` helpers

### Files Modified
- `app/Services/RecipeImportService.php` — `parseIngredientString()`, `splitNamePrep()`, `parseFractionString()`, `normalizeLlmIngredients()`, tightened prompts
- `app/Http/Requests/Recipe/StoreRecipeRequest.php` — `prepareForValidation()` fraction normalisation
- `app/Http/Requests/Recipe/UpdateRecipeRequest.php` — `prepareForValidation()` fraction normalisation
- `resources/js/components/recipes/RecipeForm.vue` — `decimalToFraction()` display helper, `parseFractionToFloat()` submit helper
- `config/version.php` — 1.2.0 → 1.2.1
- `tests/Feature/RecipeTest.php` — 3 new tests (slash fraction, unicode fraction, invalid quantity)
- `tests/Feature/RecipeImportTest.php` — 2 new tests (JSON-LD ingredient parsing, LLM quantity-in-name recovery)

### Test Results
- 125 tests, 346 assertions — PASS
- Pint: PASS
- Larastan: 0 errors

---

## 2026-04-13 — Session 32b: Shopping UX Fixes + CI Repair

### What Was Done
- **CI fixed** — Larastan was failing on PR #162 due to (1) `$this->is_recurring` / `$this->default_quantity` not resolved in `ShoppingItemResource` (fixed: use `$this->resource->`) and (2) redundant `??` null coalesce on a regex match group that always exists (fixed: removed).
- **CreateListInline copy** — Headline changed to "Create your first list", subtitle clarified it's naming one list (not listing all stores), placeholder shows singular examples, button joined flush to input (no gap), bottom hint says "more lists" not "more stores".

### Files Modified
- `app/Http/Resources/ShoppingItemResource.php`
- `app/Services/ShoppingListService.php`
- `resources/js/components/shopping/CreateListInline.vue`

---

## 2026-04-13 — Session 32: Food Step 5 — Shopping Frontend + UX Rework

### What Was Done
- **Shopping UX rework** — Replaced trip-based model (create list → complete trip) with persistent store-based lists. Lists live forever — you add items all week, check them off at the store, and "Clear Bought" resets. No more "Complete Trip" flow.
- **Recurring items** — Any item can be pinned as recurring (replaces separate Staples Manager). When you clear bought items, recurring ones auto-reappear with their default quantity. Pill-shaped toggle with "Recurring" label and golden sand active state.
- **Shopping window filter** — "All", "Next 2d", "Next 3d", "This week" pills filter items by `needed_date`. Items with no date always show. Designed for frequent shoppers (UK/Europe every-other-day pattern).
- **Ingredient picker** — "Add from Recipe" is now a two-step modal: pick recipe → select specific ingredients (all selected by default, with select/deselect all). Prevents dumping unwanted items on the list.
- **Ingredient dedup** — Adding recipe ingredients checks for existing items on the list (case-insensitive). Matching items aggregate quantities (if same unit) and append recipe attribution instead of creating duplicates.
- **List management** — Three-dot menu on list header: Rename + Delete list (with confirmation). Delete only shown when multiple lists exist.
- **Shopping as standalone nav** — Shopping promoted from a tab inside Food to its own `/shopping` route with dedicated sidebar + bottom nav entry (gated behind food module).
- **Errands eliminated** — Redundant with persistent store-based lists. All errands code removed: migration, model, controller, policy, store, views, components, routes, tests.
- **Food tab simplified** — Now just "Recipes" and "Meals" tabs (no Shopping sub-tab).
- **Migration** — Added `is_recurring` (boolean) and `default_quantity` (string) to `shopping_items` table.
- **Backend** — 3 new service methods (`clearChecked`, `moveItem`, `toggleRecurring`), 3 new controller endpoints, updated policy with 3 new methods. `createList` no longer auto-populates staples. `addItem` accepts `is_recurring` param.
- **6 new tests** — Clear checked (non-recurring deleted, recurring reset, unchecked preserved), move item, cross-family move rejection, toggle recurring with default quantity capture.
- **Bug fixes** — Autocomplete dropdown no longer reopens after adding an item. Fixed by guarding `@focus` handler against empty input.

### Files Created
- `database/migrations/2026_04_13_000004_add_recurring_to_shopping_items.php`
- `resources/js/components/shopping/ListHeader.vue`, `CreateListInline.vue`
- `resources/js/views/food/ShoppingTab.vue` (rewritten)
- `resources/js/stores/shopping.js` (rewritten)

### Files Modified
- `app/Services/ShoppingListService.php` — clearChecked, moveItem, toggleRecurring, ingredient dedup
- `app/Http/Controllers/Api/V1/ShoppingListController.php` — 3 new endpoints
- `app/Policies/ShoppingListPolicy.php` — 3 new policy methods
- `app/Models/ShoppingItem.php` — is_recurring, default_quantity
- `app/Http/Resources/ShoppingItemResource.php` — is_recurring, default_quantity
- `app/Http/Requests/Shopping/AddShoppingItemRequest.php` — is_recurring, default_quantity
- `routes/api.php` — clear-checked, move, toggle-recurring routes; errands routes removed
- `resources/js/components/shopping/ShoppingListItem.vue` — recurring toggle, move-to menu
- `resources/js/components/shopping/AddItemInput.vue` — recurring toggle, focus fix
- `resources/js/components/shopping/PreShopChecklist.vue` — shopping window filter
- `resources/js/components/layout/Sidebar.vue` — Errands → Shopping
- `resources/js/components/layout/BottomNav.vue` — Errands → Shopping
- `resources/js/router/index.js` — /shopping route added, /errands removed
- `resources/js/views/food/FoodView.vue` — Shopping tab removed, just Recipes + Meals
- `app/Providers/AppServiceProvider.php` — ErrandPolicy registration removed
- `tests/Feature/ShoppingTest.php` — 6 new tests, updated staple auto-populate test

### Files Deleted
- `resources/js/components/shopping/TripControls.vue`, `StaplesManager.vue`
- `resources/js/views/errands/ErrandsView.vue`, `resources/js/components/errands/*`
- `resources/js/stores/errands.js`
- `app/Http/Controllers/Api/V1/ErrandController.php`, `app/Models/ErrandItem.php`
- `app/Policies/ErrandPolicy.php`, `app/Http/Resources/ErrandItemResource.php`
- `app/Http/Requests/Errand/StoreErrandRequest.php`, `UpdateErrandRequest.php`
- `database/migrations/2026_04_13_000001_create_errand_items_table.php`
- `tests/Feature/ErrandTest.php`

---

## 2026-04-13 — Session 31: Food Module Step 4 — Shopping Backend + Product Catalog

### What Was Done
- **Shopping lists** — Full CRUD: create (auto-populates active staples), view, update, delete, complete trip (`is_active → false`). Route group under `/api/v1/shopping/lists`.
- **Shopping items** — Add, update, remove, check/uncheck (records user + timestamp), mark/clear on-hand (pre-shop tracking). Source enum: `manual | recipe | staple`.
- **Staples management** — Family-scoped recurring items. Auto-added on list creation via batch insert. Full CRUD + active toggle.
- **Recipe → shopping** — `POST /lists/{id}/add-recipe` extracts all ingredients (quantity+unit concat, denormalized recipe name for soft-delete safety) into shopping items.
- **Product catalog** — ~500 global items across 16 categories. `autoCategorize()` in `ShoppingListService` does exact-then-LIKE match for auto-assigning categories. Seeded via `ProductCatalogSeeder`.
- **Auto-categorization** — `ShoppingListService::autoCategorize()` queries catalog on item add/recipe import.
- **Policy + authorization** — `ShoppingListPolicy` covers all actions. Parents: full write. Children: check/uncheck + on-hand only. Policy registered for `ShoppingItem` via `Gate::policy()` in `AppServiceProvider`.
- **Review fixes** — Batch insert (not N queries) for staple auto-population, unique constraint on `product_catalog.name`, `$request->validate()` bag used in `addRecipeToList`, `ProductCatalogSeeder` registered in `DatabaseSeeder`.
- **Upsun fix** — `ProductCatalogSeeder` fallback to `firstOrCreate` when `upsert()` ON CONFLICT constraint isn't recognized on the preview environment.
- **19 tests** — Covers all CRUD, module gating, family scoping, child permissions, auto-categorization, recipe integration, cross-family rejection.

### Files Created
- `database/migrations/2026_04_13_000001-000004` — product_catalog, shopping_lists, shopping_items, staples
- `app/Models/ProductCatalog.php`, `ShoppingList.php`, `ShoppingItem.php`, `Staple.php`
- `app/Services/ShoppingListService.php`
- `app/Http/Controllers/Api/V1/ShoppingListController.php`
- `app/Http/Requests/Shopping/` — 5 form request classes
- `app/Http/Resources/ShoppingItemResource.php`, `ShoppingListResource.php`, `StapleResource.php`
- `app/Policies/ShoppingListPolicy.php`
- `app/Enums/ShoppingItemSource.php`
- `database/seeders/ProductCatalogSeeder.php`
- `tests/Feature/ShoppingTest.php` — 19 tests

### Files Modified
- `routes/api.php` — 17 new shopping routes
- `app/Providers/AppServiceProvider.php` — `Gate::policy(ShoppingItem::class, ShoppingListPolicy::class)`
- `database/seeders/DatabaseSeeder.php` — ProductCatalogSeeder registered

### PR
- [#159](https://github.com/gregqualls/kinhold/pull/159) — feat: Food Step 4 — Shopping Backend + Product Catalog (#151)

---

## 2026-04-12 — Session 30: Food Module Step 3 — Recipe Frontend UI

### What Was Done
- **Pinia recipes store** — Full data layer: CRUD, search/filter/sort, import (URL + photo), cook logs, ratings, favorites, tags, image upload. All actions return `{ success, error }`.
- **FoodView + RecipesTab** — Tab container (Recipes / Meals / Shopping), recipe grid with search, tag filter chips, sort (Recent/A-Z/Rating), favorites toggle, and compact list view (localStorage persistence).
- **RecipeCard + RecipeDetailView** — Cards with image, rating, time, tags, favorite toggle. Detail view with serving scaler, IngredientList, StepList, FamilyRating (5-star), and CookLog timeline.
- **RecipeForm + RecipeImportModal** — Create/edit/import-preview form with image upload, dynamic ingredients, dynamic steps, tag multi-select. Import modal with URL and photo tabs — photo defaults to using the uploaded image.
- **Navigation** — Food added to Sidebar and BottomNav, module-gated. Routes added to Vue Router.
- **Bug fixes (from /review)** — HTML tag/entity stripping in imported recipe text, image extraction from JSON-LD + OpenGraph on URL import, `/storage/` prefix on all image paths, tag filter scoped to recipe tags only (not task tags), cross-family tag injection prevention via `Rule::exists` scoping, N+1 fix via eager-loaded ratings in RecipeService.
- **Upsun fix** — Added `public/storage` mount to `.upsun/config.yaml` so uploaded images serve correctly on preview/production (symlink approach fails on Upsun's read-only build filesystem).
- **Version bump** — 1.0.1 → 1.1.0 (minor, new Food module frontend).

### Files Created
- `resources/js/stores/recipes.js`
- `resources/js/views/food/FoodView.vue`, `RecipesTab.vue`, `RecipeDetailView.vue`, `MealsPlaceholder.vue`, `ShoppingPlaceholder.vue`
- `resources/js/components/recipes/RecipeCard.vue`, `RecipeForm.vue`, `RecipeImportModal.vue`, `IngredientList.vue`, `StepList.vue`, `FamilyRating.vue`, `CookLogEntry.vue`

### Files Modified
- `app/Http/Controllers/Api/V1/RecipeController.php` — image upload endpoint
- `app/Http/Controllers/Api/V1/TagController.php` — `withCount('recipes')` added
- `app/Http/Requests/Recipe/StoreRecipeRequest.php`, `UpdateRecipeRequest.php` — `Rule::exists` scoping, image_path field
- `app/Http/Resources/RecipeResource.php` — N+1 fix via `$this->resource` cast
- `app/Http/Resources/TagResource.php` — `recipes_count` field
- `app/Services/RecipeImportService.php` — image extraction, HTML cleaning, photo defaults
- `app/Services/RecipeService.php` — eager-load ratings, per_page cap
- `database/seeders/DatabaseSeeder.php` — meal-category seed tags (Breakfast/Lunch/Dinner/Dessert/Snack)
- `resources/js/components/layout/Sidebar.vue`, `BottomNav.vue` — Food nav item
- `resources/js/router/index.js` — Food routes
- `routes/api.php` — image upload route
- `.upsun/config.yaml` — public/storage mount
- `phpstan-baseline.neon` — removed stale ignores, added recipes_count

### PR
- [#158](https://github.com/gregqualls/kinhold/pull/158) — feat: Food Step 3: Recipe Frontend (Complete UI) (#150)

---

## 2026-04-12 — Session 29: Food Module Step 1 — Recipe Backend

### What Was Done
- **Food module gating** — Added `'food'` to `Family::MODULES`, `getEnabledModules()` defaults, and `auth.js` modules array. Food module is enabled by default for all families. Routes protected via existing `CheckModuleAccess` middleware.
- **3 new enums** — `RecipeSourceType` (manual/url/photo/social_media), `MealSlot` (breakfast/lunch/dinner/snack), `ShoppingItemSource` (manual/recipe/staple) — future steps will consume the latter two.
- **5 new migrations** — `recipes` (soft deletes), `recipe_ingredients`, `recipe_cook_logs`, `ratings` (polymorphic — shared with restaurants in Step 6), `recipe_tag` pivot.
- **5 new models** — `Recipe` (HasUuids, SoftDeletes, scopes, computed totalTime/familyAverageRating/userRating), `RecipeIngredient`, `RecipeCookLog`, `Rating` (MorphTo), `RecipeTag` (Pivot). Tag model updated with `recipes()` BelongsToMany.
- **RecipePolicy** — Parent-only create/update/delete/restore. Children can view, rate, and log cooks.
- **RecipeService** — createRecipe, updateRecipe (replace-ingredients pattern), deleteRecipe, restoreRecipe, toggleFavorite, addCookLog, rateRecipe (upsert), searchRecipes (search/tag/favorite/sort, paginated).
- **RecipeController** — 11 endpoints: full CRUD, restore, favorite toggle, cook logs, rate, ratings list. All methods authorized via Policy.
- **4 API resources** — RecipeResource, RecipeIngredientResource, RecipeCookLogResource, RatingResource.
- **11 routes** — `/api/v1/recipes` group, all behind `module:food` middleware.
- **22 feature tests** — All passing. Covers CRUD, soft delete/restore, favorites, ratings (upsert + family average), cook logs, cross-family 403, parent/child permissions, search by title/ingredient, tag/favorite filtering, module-disabled 403.

### Security Fixes Applied During Review
- `restore()` now scopes `withTrashed()` by `family_id` to return 404 (not 403) for cross-family IDs
- `updateRecipe` rewritten with `array_intersect_key` — nullable fields can now be explicitly cleared
- `source_url` validation restricted to `url:http,https` (SSRF hardening)

### Files Created
- `app/Enums/RecipeSourceType.php`, `MealSlot.php`, `ShoppingItemSource.php`
- `database/migrations/2026_04_12_000001–000005`
- `app/Models/Recipe.php`, `RecipeIngredient.php`, `RecipeCookLog.php`, `Rating.php`, `RecipeTag.php`
- `app/Policies/RecipePolicy.php`
- `app/Http/Requests/Recipe/StoreRecipeRequest.php`, `UpdateRecipeRequest.php`
- `app/Services/RecipeService.php`
- `app/Http/Resources/RecipeResource.php`, `RecipeIngredientResource.php`, `RecipeCookLogResource.php`, `RatingResource.php`
- `app/Http/Controllers/Api/V1/RecipeController.php`
- `tests/Feature/RecipeTest.php`

### Files Modified
- `app/Models/Family.php` — food module added to MODULES + getEnabledModules()
- `app/Models/Tag.php` — recipes() relationship added
- `resources/js/stores/auth.js` — food added to modules array
- `routes/api.php` — recipe route group added

---

## 2026-04-10 — Session 28: GDPR, Vault Fix, Self-Hosted Polish

### What Was Done
- **GDPR account & family deletion (#96)** — `AccountDeletionService` handles full cleanup: file deletion, token revocation, session cleanup, managed children cascade, orphaned family cleanup. `FamilyDeletionService` for nuclear family deletion. `DELETE /api/v1/settings/account` (password-confirmed) and `DELETE /api/v1/family` (password + type family name). Enhanced `removeMember` to use the same cleanup service. Demo family guard on all deletion endpoints. Danger Zone UI in Settings for both parents and children with confirmation modals.
- **Vault file uploads bug (#121)** — Fixed `Content-Type` header conflict in multipart form data upload. Removed explicit header override so axios auto-detects FormData and sets correct boundary.
- **Self-hosted email verification** — Auto-verify users on registration when `SELF_HOSTED=true`, hide verification banner, skip resend endpoint. Self-hosted users no longer see a nag banner they can't resolve.

### Security Hardening
- Rate limiting (5 req/min) on account and family deletion endpoints
- Passwordless account guard — Google-only and managed accounts rejected with clear message
- Demo family protected from all deletion operations (account, member, family)
- Last-parent guard prevents orphaning non-managed family members

### Housekeeping
- Closed #124 (demo data refresh — already fixed by `app:refresh-demo` daily cron)
- Closed #126 (demo email verification — already fixed by seeder)
- Moved #143 (demo CTA banner) to backlog

### Files Created
- `app/Services/AccountDeletionService.php`
- `app/Services/FamilyDeletionService.php`

### Vault & Document Fixes
- **Document downloads** — Fixed auth failure when opening vault documents in a new tab. Replaced `<a href>` links with axios blob download (bearer token auth). No more Google OAuth redirect loop on document download.
- **Document delete UI** — Added delete button and confirmation modal to vault document list. Uses `DELETE /api/v1/vault/documents/{id}` endpoint with proper update authorization.
- **Demo family vault guards** — Upload button hidden and delete button hidden for demo family members. Uploads/deletes return 403 for demo family to prevent abuse and storage bloat.
- **Config fix** — Renamed `config/filesystem.php` → `config/filesystems.php` (Laravel convention). Private disk definition now loads correctly, fixing vault file storage.
- **Review blockers fixed** — `deleteDocument` now requires `update` policy (not `view`). `cleanupOrphanedFamily` uses `Family::find()->delete()` (Eloquent, fires model events) instead of raw `DB::table()`. OAuth-only account holders get actionable error message directing them to set a password first.

### Files Created
- `app/Services/AccountDeletionService.php`
- `app/Services/FamilyDeletionService.php`

### Files Modified
- `app/Http/Controllers/Api/V1/AuthController.php` — self-hosted email verification skip, expose `slug` in `/user` family response
- `app/Http/Controllers/Api/V1/FamilyController.php` — enhanced removeMember, deleteFamily endpoint
- `app/Http/Controllers/Api/V1/SettingsController.php` — deleteAccount endpoint
- `app/Http/Controllers/Api/V1/VaultController.php` — demo guard on upload, fix deleteDocument auth
- `app/Http/Resources/DocumentResource.php` — relative download URL (no double /api/v1 prefix)
- `config/filesystems.php` — added private disk definition (renamed from filesystem.php)
- `resources/js/App.vue` — hide verification banner on self-hosted
- `resources/js/services/api.js` — interceptor to strip Content-Type for FormData (fixes multipart boundary)
- `resources/js/stores/vault.js` — remove explicit Content-Type override
- `resources/js/views/settings/SettingsView.vue` — Danger Zone section with deletion modals, demo popup
- `resources/js/views/vault/VaultEntryView.vue` — blob download, delete button, demo family guards
- `routes/api.php` — new DELETE routes with rate limiting

---

## 2026-04-09 — Session 27: Launch Day 2 — Versioning, Docker Polish

### What Was Done
- **Versioning infrastructure (#117)** — Created `config/version.php` as single source of truth (v1.0.0). `UpdateCheckService` checks GitHub Releases API once per day (cached 24h), fails silently if offline or disabled. Version and update info exposed in `/api/v1/config` (public) and MCP `get-settings` tool. New "About Kinhold" section in Settings shows version, license, update banner (parent-only, dismissible per-version via localStorage), and links to GitHub/releases/website. Child view shows version only.
- **GitHub Actions release workflow (#117)** — `.github/workflows/release.yml` auto-creates GitHub Release with auto-generated notes when a `v*` tag is pushed. Uses `softprops/action-gh-release@v2`.
- **Docker polish (#142)** — Changed `.env.docker-simple` defaults: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database` (sessions table migration already exists, entrypoint runs migrate). Created `.dockerignore` to exclude `.git`, `node_modules`, `vendor`, tests, docs, and dev tooling from Docker build context. Added `DISABLE_UPDATE_CHECK` env var to `.env.example` and `.env.docker-simple`.

### Files Created
- `config/version.php`
- `app/Services/UpdateCheckService.php`
- `.github/workflows/release.yml`
- `.dockerignore`

### Files Modified
- `routes/api.php` — version + update_available in `/api/v1/config`
- `app/Mcp/Tools/GetSettings.php` — app_version + update_available in MCP response
- `resources/js/views/settings/SettingsView.vue` — About section (parent + child views)
- `.env.docker-simple` — production defaults, database sessions, update check opt-out
- `.env.example` — DISABLE_UPDATE_CHECK env var

---

## 2026-04-08 — Session 26: Launch Day 1 — Social Card, Self-Hosted Mode, Quick Fixes

### What Was Done
- **Self-hosted mode (#139)** — Added `SELF_HOSTED` env var to `.env.example` (default `false`) and `.env.docker-simple` (default `true`). Exposed as `self_hosted` flag in `/api/v1/config`. Router guard now skips the marketing landing page and redirects unauthenticated users directly to `/login` (which chains to `/register` on first boot) when `self_hosted` is true. Fixed race condition by awaiting `fetchAppConfig()` in auth store init.
- **OG/Twitter meta tags (#140)** — Added full Open Graph + Twitter Card meta block to `app.blade.php`. URLs use `{{ url('/') }}` (reads `APP_URL`) so self-hosters get correct preview URLs automatically. Greg added 1200×630 `public/images/og-card.png` social card image.
- **License + domain fixes (#141)** — Updated 6 "MIT License" references to "Elastic License 2.0" across `LandingView.vue`, `TermsView.vue`, `PrivacyPolicyView.vue`. Fixed 3 "kinhold.com" references to "kinhold.app" in Terms and Privacy pages.
- **404 page (#141)** — Created `NotFoundView.vue` (styled to match Terms/Privacy, dark mode support). Added catch-all `/:pathMatch(.*)*` route as last entry in router with `meta: { isOpen: true }`.
- **PR #144 open** — All changes bundled into one PR. `/check` passes (8/8). CI running on GitHub Actions. Upsun preview at `pr-144-vzmcodi-2rozcvqjtjdta.ch-1.platformsh.site`.

### Files Created
- `resources/js/views/NotFoundView.vue`
- `public/images/og-card.png`

### Files Modified
- `routes/api.php` — `self_hosted` added to config response
- `resources/js/stores/auth.js` — `await fetchAppConfig()`
- `resources/js/router/index.js` — self-hosted redirect + 404 catch-all
- `resources/views/app.blade.php` — OG/Twitter meta tags
- `resources/js/views/LandingView.vue` — license fix
- `resources/js/views/TermsView.vue` — license + domain fix
- `resources/js/views/PrivacyPolicyView.vue` — license + domain fix
- `.env.example` — `SELF_HOSTED=false`
- `.env.docker-simple` — `SELF_HOSTED=true`

### In Progress (PR #144)
- Not yet merged — awaiting Greg's final review + merge.

---

## 2026-04-06 — Session 25: Housekeeping & Infrastructure

### What Was Done
- **GitHub cleanup** — Closed stale issues (#33 auctions, #17 calendar visibility, #20 duplicate meal planner). Renamed project board from "Q32 Hub" to "Kinhold". Assigned all 20 open issues to milestones (was 8 unassigned).
- **Milestone restructure** — Phase A renamed to "Make It Solid" (foundational work: GDPR, bugs, infra). New Phase F created for food features (#65, #66, #67) so they no longer block foundational work.
- **`/check` refactor** — Moved 10-check logic from 117-line LLM prompt to `scripts/check.sh` (bash). `check.md` simplified to ~15 lines — haiku just runs the script and reports. Script is macOS-compatible and CI-reusable.
- **New issues created** — #134 (landing page separation), #135 (/check refactor), #137 (AI assistant usage limits), #138 (license single-family enforcement).
- **ROADMAP.md updated** — Fully rewritten to match new milestone structure including Phase F.

### Files Created
- `scripts/check.sh`

### Files Modified
- `.claude/commands/check.md` — simplified to haiku reporter
- `docs/ROADMAP.md` — restructured phases

### In Progress (PR #136)
- Not yet merged — PR open, CI green, Upsun preview active.

---

## 2026-04-05 — Session 24: Modular Dashboard

### What Was Done
- **Customizable per-user dashboard** — JSON-driven widget grid stored per user in `dashboard_config` column. 12 purpose-built widget types, each designed for specific sizes.
- **Widget types:** welcome, countdown, my-tasks, family-tasks, filtered-tasks, todays-schedule, points-summary, leaderboard, activity-feed, rewards-shop, badge-collection, quick-actions.
- **Edit mode** — Drag-and-drop reordering (sortablejs), size toggle (S/M/L per widget's supported sizes), add/remove widgets, save/cancel.
- **Widget picker** — Categorized modal with size selector. Filtered-tasks widget has tag multi-select and due date range picker.
- **Filtered tasks widget** — Configurable task view filtered by tags and date range. Stored as `filters` object in config.
- **Multi-column layouts** — Task, schedule, and feed widgets use CSS columns at md/lg for natural content flow.
- **Purpose-built rendering** — Badges use BadgeShowcase with hex icons, Rewards use FeaturedRewards with affordability indicators, Leaderboard uses LeaderboardStrip at md size.
- **Dynamic widget heights** — Each widget declares height per size (120px–360px or auto). No wasted whitespace.
- **Points summary widget** — Balance + recent activity feed in one card.
- **Config v2 schema** — Simplified: `{ type, size }` per widget. Auto-migration from v1 on dashboard load.
- **ManageDashboard MCP tool** — get/set/add_widget/remove_widget/reorder with filter validation.
- **Dashboard builder playbook** — AI-guided layout creation.
- **Sidebar reorder** — Dashboard, Assistant, Calendar, Tasks, Points, Rewards, Badges, Vault.
- **Security** — `dashboard_config` guarded from mass assignment, widget filter validation on API + MCP, title sanitization.

### Files Created
- `app/Http/Controllers/Api/V1/DashboardController.php`
- `app/Mcp/Tools/ManageDashboard.php`
- `app/Services/DashboardConfigService.php`
- `database/migrations/2026_04_03_100000_add_dashboard_config_to_users_table.php`
- `playbooks/dashboard/dashboard-builder.md`
- 12 widget components in `resources/js/components/dashboard/widgets/`
- `resources/js/components/dashboard/DashboardWidget.vue`, `DashboardToolbar.vue`, `SizeToggle.vue`, `WidgetPickerModal.vue`, `widgetRegistry.js`
- `resources/js/stores/dashboard.js`, `resources/js/composables/useWidgetData.js`

### Files Modified
- `app/Models/User.php` — dashboard_config column + guarded
- `app/Mcp/Servers/KinholdServer.php` — ManageDashboard registered
- `app/Services/AgentService.php` — dashboard system prompt
- `resources/js/components/layout/Sidebar.vue` — nav reorder
- `resources/js/views/dashboard/DashboardView.vue` — full rewrite
- `routes/api.php` — dashboard endpoints
- `package.json` — sortablejs dependency

---

## 2026-04-03 — Session 23: Rewards Marketplace Overhaul

### What Was Done
- **Quantity & expiration** — Rewards can have limited stock (decrement on purchase with DB locking) and optional expiration dates. Stock badges ("3 left", "Sold Out") and countdown labels on cards.
- **Visibility controls** — Rewards can be scoped to everyone, parents only, children only, specific family members (UUID array), or by age range (min/max). All enforced at API, MCP, and Policy layers.
- **Search, filter, sort** — Client-side search bar, filter chips (All/Affordable/Available), sort dropdown (price/name/newest) with localStorage persistence.
- **Edit UI** — Reusable `RewardForm.vue` component for create and edit. PencilIcon/TrashIcon replace text links. Form scrolls into view when editing from auction cards.
- **Bidding/auction system** — Two modes: timed (auto-resolve via scheduled command) and parent-called (manual close). Points held on bid, released when outbid/cancelled, converted to purchase on win. `AuctionService` with full DB transaction locking. `RewardBid` model, `reward_bids` table, `ResolveAuctions` artisan command.
- **Auction card redesign** — Full-width distinct layout with colored header bar, two-column body (info + bid stats), clear action bar. Shows leading bidder (parent view), "Winning!" state, countdown.
- **MCP parity** — All new fields and actions (bid, close_auction, cancel_auction) added to `manage-rewards` and `purchase-reward` MCP tools with Policy authorization.
- **Sidebar nav** — Rewards added as top-level sidebar item with GiftIcon. Active state fix for nested routes.
- **Security** — Family-scoped `visible_to` validation, Policy authorization on all auction endpoints (API + MCP), batch-loaded names (no N+1), aria-labels throughout.
- **Toast notifications** — Success/error feedback for purchase, bid, close, cancel actions.

### Files Created
- `app/Enums/RewardVisibility.php`, `app/Enums/RewardType.php`
- `app/Models/RewardBid.php`, `app/Services/AuctionService.php`
- `app/Console/Commands/ResolveAuctions.php`
- `resources/js/components/points/RewardForm.vue`, `BidModal.vue`
- 3 database migrations

### Files Modified
- `app/Models/Reward.php`, `app/Models/User.php`
- `app/Http/Controllers/Api/V1/RewardsController.php`
- `app/Policies/RewardPolicy.php`
- `app/Services/PointsService.php`
- `app/Mcp/Tools/ManageRewards.php`, `PurchaseReward.php`
- `resources/js/components/points/RewardCard.vue`, `FeaturedRewards.vue`
- `resources/js/views/points/RewardsView.vue`
- `resources/js/stores/points.js`
- `resources/js/components/layout/Sidebar.vue`
- `routes/api.php`, `routes/console.php`
- `database/seeders/DatabaseSeeder.php`

---

## 2026-04-02 — Session 22: MCP Tool Pagination Fix

### What Was Done
- **MCP tool pagination bug** — Discovered that `laravel/mcp` defaults to 15 tools per page. With 19 registered tools, vault (`manage-vault`, `manage-vault-access`) and playbook (`list-playbooks`, `get-playbook`) tools were stranded on a never-fetched page 2. Override `defaultPaginationLength` to 50 in `KinholdServer`.

### Files Modified
- `app/Mcp/Servers/KinholdServer.php` (added `defaultPaginationLength = 50`)

---

## 2026-04-02 — Session 21: Fresh Demo Family + Try the Demo

### What Was Done
- **Demo UX fixes** — Demo users now skip onboarding and don't see the email verification banner. Added `email_verified_at` and `onboarding_completed_at` to all 5 seeded demo users.
- **Daily demo refresh** — New `app:refresh-demo` artisan command re-seeds the demo family so data always feels fresh. Scheduled daily at 03:05 via Laravel scheduler (Upsun's `schedule:work` worker picks it up automatically).
- **Hardened demo passwords** — Demo users now get `Str::random(32)` passwords per seed run instead of `bcrypt('password')`. Passwords are never stored or displayed, change daily with re-seed.
- **"Try the Demo" feature** — One-click demo access from landing page and login page. Interactive modal lets visitors choose a family member (Mike, Sarah, Emma, Jake, Lily) to log in as. Dedicated `POST /api/v1/demo-login` endpoint creates Sanctum tokens directly — no password needed. Works for managed accounts (Jake, Lily) too.
- **Conditional visibility** — Demo buttons only appear when the demo family exists (`demo_available` flag in `/api/v1/config`). Self-hosted instances without demo data won't show them.
- **ESLint cleanup** — Eliminated all 43 pre-existing warnings across 23 files (unused imports, dead code, console.error statements).

### Files Created
- `app/Console/Commands/RefreshDemo.php`
- `resources/js/components/common/DemoModal.vue`

### Files Modified
- `database/seeders/DatabaseSeeder.php` (random passwords, verification + onboarding fields)
- `routes/console.php` (daily refresh schedule)
- `app/Http/Controllers/Api/V1/AuthController.php` (added `demoLogin()`)
- `routes/api.php` (demo-login route, `demo_available` config flag)
- `resources/js/stores/auth.js` (added `demoLogin()` action)
- `resources/js/views/LandingView.vue` (demo button + modal)
- `resources/js/views/auth/LoginView.vue` (demo link + modal)

---

## 2026-04-02 — Session 20: Unified Calendar

### What Was Done
- **Unified event model** — Merged `FeaturedEvent` and `FamilyEvent` into a single `family_events` table. Migration copies existing featured events data. Any calendar event can now optionally be "featured" on the dashboard with personal or family scope.
- **Manual calendar events** — Full CRUD from the calendar UI. "Add Event" button in header, click-a-day to pre-fill date, click-a-manual-event to edit. Supports title, date/time, all-day, end time, location, recurrence, visibility, and feature-on-dashboard toggle.
- **Visibility controls** — Events can be visible (full details), busy (others see "Busy" block), or private (only creator sees it). Enforced at API and MCP layers.
- **Recurrence expansion** — Weekly/monthly/yearly events now show all occurrences within the calendar view's date range via `occurrencesInRange()` method.
- **Countdown banner fixes** — Dismiss persists in localStorage (fixed async prop race condition), auto-hides past events (backend + frontend), parent management actions (edit, remove countdown, delete from banner).
- **Unified EventModal** — Shared by dashboard (featured mode) and calendar (calendar mode). DRY — replaced `FeaturedEventModal`.
- **Visual source distinction** — Tasks show with dashed amber borders, manual events with solid colored borders, Google/ICS events keep their calendar colors. Legend updated.
- **Calendar view mode persistence** — Week/month/day selection saved in localStorage.
- **MCP parity** — `view-calendar` fixed empty listing bug, added `create_event`/`update_event`/`delete_event`. `manage-featured-events` repointed to unified model.
- **Security hardening** — Policy-based auth on CRUD (creator OR parent), parent-only guards on featured_scope/is_countdown/icon, ownership checks on MCP update/delete.
- **Countdown toggle race condition fixed** — `setCountdown` now captures `wasCountdown` before blanket unset.

### Files Created
- `database/migrations/2026_04_02_095108_add_featured_columns_to_family_events_table.php`
- `app/Policies/FamilyEventPolicy.php`
- `resources/js/components/common/EventModal.vue`
- `tests/Feature/CalendarEventTest.php` (15 new tests)

### Files Modified
- `app/Models/FamilyEvent.php` (new fields, accessors, scopes, `occurrencesInRange()`)
- `app/Http/Controllers/Api/V1/CalendarController.php` (visibility, recurrence, CRUD with policy)
- `app/Http/Controllers/Api/V1/FeaturedEventController.php` (repointed to FamilyEvent)
- `app/Http/Resources/FeaturedEventResource.php` (adapted for unified model)
- `app/Policies/FeaturedEventPolicy.php` (adapted for FamilyEvent)
- `app/Mcp/Tools/ViewCalendar.php` (fixed listing, added CRUD)
- `app/Mcp/Tools/ManageFeaturedEvents.php` (repointed to FamilyEvent)
- `resources/js/views/calendar/CalendarView.vue` (event creation, source styling, click handlers)
- `resources/js/components/calendar/TimeGrid.vue` (event-click emit)
- `resources/js/components/featured-events/CountdownBanner.vue` (dismiss persistence, parent actions)
- `resources/js/components/featured-events/FeaturedEventsSection.vue` (EventModal import)
- `resources/js/stores/calendar.js` (CRUD actions, view mode persistence)
- `database/seeders/DatabaseSeeder.php` (FamilyEvent for featured events)
- `routes/api.php` (FamilyEvent route binding)

---

## 2026-04-01 — Session 19: Vault Overhaul

### What Was Done
- **Fixed 9 vault CRUD bugs** — Entry creation (data format mismatch), edit entry (was TODO stub), permissions display (missing user relation), document delete (polymorphic relation bug), document links, update validation, grant permission field name, category filtering, PHPStan baseline cleanup.
- **Markdown editor** — Replaced key/value field design with Milkdown WYSIWYG editor. Bold, italic, headings, lists, code, blockquote, HR toolbar. Entries store markdown body + optional sensitive fields. Legacy entries still display via fallback.
- **Category CRUD** — Create, edit, delete custom categories with 10 icon options. Backend, frontend, and MCP tool all updated.
- **Permissions UI** — Share button + modal to grant/revoke access per family member with view/edit levels.
- **Document upload** — Upload button on entry detail with progress indicator.
- **Kids personal vault** — `is_personal` flag on entries. Children can create/edit/delete their own personal entries. Parents see everything. Policy + MCP enforced.
- **Vault playbooks** — 5 community-contributable `.md` playbook files (house manual, medical, vehicle, school, emergency contacts). Two new MCP tools (`list-playbooks`, `get-playbook`). Agent system prompt updated to use playbooks for guided data entry.

### Files Created
- `resources/js/components/vault/MarkdownEditor.vue`, `MilkdownEditorCore.vue`
- `app/Policies/VaultCategoryPolicy.php`
- `app/Mcp/Tools/ListPlaybooks.php`, `GetPlaybook.php`
- `database/migrations/2026_04_01_192652_add_is_personal_to_vault_entries.php`
- `playbooks/vault/` — 5 playbook files

### Files Modified
- `app/Http/Controllers/Api/V1/VaultController.php` (bug fixes + category CRUD + personal entries)
- `app/Http/Resources/VaultEntryResource.php` (vault_category_id + is_personal + user data in permissions)
- `app/Mcp/Tools/ManageVault.php` (category CRUD + personal entries + N+1 fix)
- `app/Models/VaultEntry.php` (is_personal)
- `app/Policies/VaultEntryPolicy.php` (personal entry access for children)
- `resources/js/views/vault/` (all 3 views rewritten)
- `resources/js/stores/vault.js` (category CRUD actions)
- `resources/js/components/common/BaseModal.vue` (xl size)
- `routes/api.php` (category routes)
- `package.json` (milkdown deps)

---

## 2026-04-01 — Session 18: Chat → Agent (PR #119)

### What Was Done
- **Replaced chatbot with MCP-powered agent** — Natural language input → Claude tool_use API → executes MCP tools → returns structured results. All 18 existing MCP tools available to the agent with zero duplication.
- **AgentService + ToolRegistry** — New service layer: `AgentService` orchestrates the tool execution loop (max 10 iterations), `ToolRegistry` maps MCP tool schemas to Claude's tool_use format and executes them.
- **Markdown rendering** — Assistant responses render as formatted HTML (headings, bold, bullets, horizontal rules) using `marked` + `DOMPurify` for XSS safety.
- **Renamed Chat → Assistant** — CpuChipIcon replaces chat bubble across Sidebar, BottomNav, Dashboard quick action. Action-oriented suggested prompts. Accuracy disclaimer.
- **Safety guardrails** — System prompt constrains agent to tool-only scope. No off-topic, no physical tasks, no prompt injection. Asks clarifying questions for incomplete requests (assignee, due date, points).
- **Removed ChatbotService** — Dead code. `availableProviders()` moved to `AgentService`. Static context dumping replaced by on-demand tool calls.
- **Fixed task tag sync bug** — Pre-existing bug: `task_tag` UUID pivot table lacked a model to generate IDs. Added `TaskTag` pivot model with `HasUuids`.
- **Closed 4 issues** — #113 (self-hosting, already done), #108 (hidden badges, already done), #107 (child safety, superseded by MCP policies), #109 (stateless messages, superseded by agent architecture).

### Files Created
- `app/Services/Agent/ToolRegistry.php`, `app/Services/AgentService.php`
- `app/Models/TaskTag.php` (pivot model)
- `database/migrations/2026_04_01_154707_add_metadata_to_chat_messages_table.php`

### Files Modified
- `app/Http/Controllers/Api/V1/ChatController.php` (uses AgentService)
- `app/Http/Controllers/Api/V1/SettingsController.php` (uses AgentService::availableProviders)
- `app/Models/ChatMessage.php` (metadata column + cast)
- `app/Models/Task.php` (TaskTag pivot model on tags relationship)
- `app/Services/AiProviders/AnthropicProvider.php` (askWithTools method)
- `resources/js/views/chat/ChatView.vue` (markdown, robot icon, disclaimer, suggested actions)
- `resources/js/views/dashboard/DashboardView.vue` (Assistant quick action)
- `resources/js/components/layout/Sidebar.vue`, `BottomNav.vue` (Chat → Assistant)
- `package.json` (added marked, dompurify)

### Files Removed
- `app/Services/ChatbotService.php`

---

## 2026-04-01 — Session 17: SDLC Pipeline & Quality Gates (PR #118)

### What Was Done
- **7 new slash commands** — `/check` (10 quality gates), `/review` (7-category code review), `/pr` (automated PR creation), `/qa` (CI + Upsun preview checker), `/merge` (safe merge with deploy monitoring), `/fix` (auto-fix Pint + ESLint), `/playbook` (interactive pipeline guide)
- **3 improved commands** — `/kickoff` (branch creation offer), `/handoff` (quality snapshot), `/ship` (comprehensive pre-merge audit)
- **Quality tooling installed** — ESLint with Vue 3 plugin + browser globals, Pint config (Laravel preset), PHPStan level 5 with Larastan + baseline (203 pre-existing errors baselined)
- **CI lint job added** — Third parallel job in GitHub Actions: Pint, Larastan, ESLint
- **Codebase-wide formatting** — Pint auto-fixed 87 PHP files, ESLint auto-fixed 356 Vue/JS warnings
- **Vulnerable deps patched** — `phpseclib` (CVE-2026-32935, HIGH) and `league/commonmark` (CVE-2026-33347, MEDIUM)
- **Root cleanup** — Moved `plan.md` → `docs/plans/`, cleaned `.gitignore`, consolidated permissions

### Files Created
- `.claude/commands/{check,fix,merge,playbook,pr,qa,review}.md`
- `eslint.config.js`, `pint.json`, `phpstan.neon`, `phpstan-baseline.neon`
- `docs/plans/family-member-management.md`

### Files Modified
- `.claude/commands/{handoff,kickoff,ship}.md` (improved)
- `.github/workflows/ci.yml` (lint job added)
- `.gitignore`, `CLAUDE.md`, `CONTRIBUTING.md`, `docs/CONVENTIONS.md`
- `package.json` (ESLint + globals devDeps)
- 87 PHP files (Pint formatting), 53 Vue files (ESLint attribute ordering)
- `composer.lock` (security patches)

### Pipeline Flow
```
/kickoff → code → /review → /check → /pr → /qa → /handoff → /merge → /cleanup
```

---

## 2026-04-01 — Session 16: Self-Hosting Setup + Open-Source Hygiene (#113)

### What Was Done
- **Zero-dependency Docker setup (PR #115)** — Single-container `docker-compose.simple.yml` with SQLite, file cache, sync queue. No PostgreSQL or Redis required. `./setup-simple.sh` one-command bootstrap. Auto APP_KEY generation with persistence across container restarts. Dockerfile bumped to PHP 8.4 with SQLite support.
- **Graceful feature degradation (PR #115)** — Public `/api/v1/config` endpoint for pre-auth service detection. Google OAuth buttons hide when credentials not configured. Calendar, AI Chat, and Settings show "not configured" notices instead of breaking. Runtime service detection in auth store.
- **First-boot experience (PR #115)** — Auto-redirect from login → register when no users exist. Welcome messaging for first family setup.
- **Self-hosting documentation (PR #115)** — Comprehensive `SELF-HOSTING.md` with setup options, optional services, reverse proxy examples (Caddy/Nginx), backup strategies, SQLite→PostgreSQL migration path. Improved `.env.example` with clear sections and documented alternatives. Updated README with "Easiest" setup option.
- **Open-source hygiene (PR #116)** — Fixed license references from MIT → Elastic License 2.0 across all project files (composer.json, CLAUDE.md, PRINCIPLES.md, ROADMAP.md, CHANGELOG.md, competitive analysis). Added CODE_OF_CONDUCT.md (Contributor Covenant 2.1), SECURITY.md (vulnerability disclosure policy), PR template, and GitHub Actions CI (PHPUnit + Vite build on every PR/push).
- **CI fixes** — Created `bootstrap/cache` directory in CI, switched from `artisan test` to `./vendor/bin/phpunit`, added `tests/Unit/.gitkeep`, updated `phpunit.xml` for PHPUnit 11 compatibility (removed deprecated attributes, migrated coverage config to `<source>` element), fixed family factory slug uniqueness for SQLite.
- **Versioning issue created (#117)** — Planned: semantic versioning, GitHub Releases workflow, self-hosted update notifications.

### Files Created
- `docker-compose.simple.yml`, `.env.docker-simple`, `setup-simple.sh`, `SELF-HOSTING.md`
- `CODE_OF_CONDUCT.md`, `SECURITY.md`, `.github/pull_request_template.md`, `.github/workflows/ci.yml`
- `tests/Unit/.gitkeep`

### Files Modified
- `Dockerfile`, `docker/entrypoint.sh`, `docker/php/php.ini`, `docker-compose.yml`
- `routes/api.php`, `resources/js/stores/auth.js`, `resources/js/router/index.js`
- `resources/js/views/auth/LoginView.vue`, `RegisterView.vue`, `settings/SettingsView.vue`
- `app/Http/Controllers/Api/V1/ChatController.php`, `CalendarController.php`, `SettingsController.php`
- `composer.json`, `phpunit.xml`, `database/factories/FamilyFactory.php`
- `.env.example`, `README.md`, `CLAUDE.md`, `PRINCIPLES.md`, `CHANGELOG.md`
- `docs/ROADMAP.md`, `docs/kinhold-competitive-analysis.md`

### PRs
- #115 — `feature/113-self-hosting-simple-setup` (merged)
- #116 — `chore/open-source-hygiene` (merged)

---

## 2026-03-31 — Session 15: Unified Policy-Based Auth for MCP + API (#98)

### What Was Done
- **4 new Laravel Policies created** — `BadgePolicy`, `TagPolicy`, `RewardPolicy`, `FeaturedEventPolicy` — each enforcing parent-only write access as the single source of truth for both API and MCP layers.
- **`authorize()` helper added to `ScopesToFamily` trait** — MCP tools can now delegate to Laravel Gate/policies via `$this->authorize($ability, $model)`, returning a structured error response if denied.
- **`Badge::maskHidden()` static method** — Shared presentation logic extracted to the model. Web UI hides from all users (surprise mechanic preserved); MCP shows parents full badge details (management interface).
- **8 MCP tools migrated** — `ManageBadges`, `ManageTags`, `ManageRewards`, `ManageFeaturedEvents`, `ManageTasks`, `ManageVault`, `ManageVaultAccess`, `CompleteTask` all replaced inline `requireParent()` / ad-hoc checks with policy-based `$this->authorize()` calls.
- **4 API controllers migrated** — `TagController`, `RewardsController`, `BadgesController`, `FeaturedEventController` replaced remaining inline `isParent()` checks with `$this->authorize()` policy calls.
- **4 new security tests** — `test_child_cannot_create_tag`, `test_child_cannot_delete_tag`, `test_child_sees_masked_hidden_badges`, `test_parent_sees_masked_hidden_badges_in_web_ui`. Total: 45 tests, all passing.
- **MCP-first guardrails principle established** — Authorization for any module now lives in one policy file; both API and MCP inherit changes automatically. Foundation laid for Issue #107 (child access controls).

### Files Modified
- New: `app/Policies/BadgePolicy.php`, `TagPolicy.php`, `RewardPolicy.php`, `FeaturedEventPolicy.php`
- Modified: `app/Mcp/Tools/Concerns/ScopesToFamily.php`, `app/Models/Badge.php`
- Modified MCP tools: `ManageBadges.php`, `ManageTags.php`, `ManageRewards.php`, `ManageFeaturedEvents.php`, `ManageTasks.php`, `ManageVault.php`, `ManageVaultAccess.php`, `CompleteTask.php`
- Modified controllers: `TagController.php`, `RewardsController.php`, `BadgesController.php`, `FeaturedEventController.php`
- Modified: `tests/Feature/SecurityTest.php` (4 new tests)

### PR
- #114 — `fix/98-mcp-policy-auth` (merged)

---

## 2026-03-31 — Session 14: Self-Hosting Accessibility Planning

### What Was Done
- **Self-hosting accessibility research** — Analyzed n8n's open-source model (licensing, Docker setup, feature gating strategy) and mapped it to Kinhold's current external dependencies.
- **Dependency audit** — Cataloged all external service requirements (PostgreSQL, Redis, SMTP, Google OAuth, Anthropic API) and identified which are truly required vs optional.
- **3-sprint implementation plan** — Documented at `.claude/plans/self-hosting-accessibility.md`:
  1. Zero-Config First Run: SQLite default, `docker-compose.simple.yml` (2 services), auto APP_KEY
  2. Graceful Feature Degradation: runtime feature detection, `/api/v1/config` endpoint, conditional UI
  3. DX & Polish: first-boot setup wizard, `SELF-HOSTING.md`, updated README
- **New architecture principle** — Added #5 to CLAUDE.md: "Self-hostable by default — We don't gate features — we gate operational complexity."
- **GitHub issue #113** — Created with full sprint checklists for tracking.

### Files Modified
- `CLAUDE.md` — Added architecture principle #5 (self-hostable by default)

### No PR
- Planning session only, no code changes to ship.

---

## 2026-03-29 — Session 13: Security Audit + Google Linking + Email Verification

### What Was Done
- **Comprehensive security audit** — Found and fixed 22 vulnerabilities (3 Critical, 6 High, 8 Medium, 5 Low). Full details in PR #110.
  - **Critical:** Cross-family data isolation (vault SSNs, tasks, rewards, badges accessible across families), OAuth token leaked in URL, no login rate limiting
  - **High:** Google OAuth account takeover via email auto-linking, Calendar OAuth CSRF (unsigned state), SSRF via ICS subscription, vault accepted any file type
  - **Medium:** Self-selecting parent role on invite join, weak passwords (only min:8), short invite codes, error messages leaking internals, cross-family validation gaps
- **Google account linking** — Users who registered with email/password can now link Google from Settings. When trying Google sign-in with an existing account, they're prompted for their password to confirm the link (instead of being rejected).
- **Email verification on registration** — New users get a verification email. Dismissable amber banner in the app until verified. Resend endpoint throttled to 3/min. Existing users grandfathered.
- **41 automated tests** — 31 security tests + 5 Google link tests + 5 email verification tests. Model factories created (FamilyFactory, UserFactory).

### Files Modified
- 6 controllers (Auth, Google Auth, Badges, Rewards, Calendar, Chat, Vault)
- 2 policies (VaultEntryPolicy, TaskPolicy) — added family_id checks to all methods
- 4 form requests (RegisterRequest, StoreTaskRequest, StoreVaultEntryRequest, GrantPermissionRequest)
- User model (MustVerifyEmail, guarded fields), UserResource (google_id boolean, email_verified_at)
- IcsCalendarService (SSRF protection), routes/api.php (rate limiting, new endpoints), routes/web.php (verification, link callback)
- SPA: auth store (code exchange, pending link, resend verification), LoginView (link confirmation UI), SettingsView (Google link/unlink), App.vue (verification banner)
- New: PendingLinkException, FamilyFactory, UserFactory, SecurityTest, GoogleLinkTest, EmailVerificationTest

### PR
- #110 — `security/audit-and-fixes` (pending merge)

---

## 2026-03-29 — Session 12: AI Chat Activation + OAuth MCP Connector

### What Was Done
- **Laravel Passport OAuth 2.0 for MCP** — Claude Desktop can now connect with just the URL `https://kinhold.app/mcp`, no token copy-paste needed. Google OAuth popup → approve → connected.
  - Installed `laravel/passport`, configured `api` guard, added `Mcp::oauthRoutes()`
  - Added session-based Google OAuth login route (`/login` → `/auth/google/oauth-callback`) for Passport's consent screen
  - Published and customized MCP authorize view (`resources/views/mcp/authorize.blade.php`)
  - PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY set on Upsun via REST API (CLI couldn't parse PEM)
  - SPA catch-all regex updated to not swallow `/oauth/` and `/.well-known/` routes
- **Email notifications fixed** — Resend was being overridden by Upsun's platform SMTP injection. Disabled via `upsun environment:info enable_smtp false`. Confirmed delivery working.
- **AI Chat activated** — Two-tab UI in Settings: "Use Kinhold AI" (platform key) vs "My Own API Key" (BYOK). `ai_mode` field added to family settings.
  - `ChatbotService::resolveProvider()` respects `ai_mode` — kinhold mode uses `ANTHROPIC_API_KEY` env var, byok uses encrypted family key
  - Fixed missing AI & Integrations section: `window.location.origin` in Vue template caused silent TypeError that dropped the entire `<SettingsSection>` — moved to `const appOrigin` in script setup
  - Fixed chat gate: `ChatView.vue` now checks `ai_mode === 'kinhold'` OR `ai_has_key` (was only checking BYOK key)
  - Fixed message display: API returns `{role, message}` but template expected `{sender, text}` — normalized in chat store
  - Fixed model: API key account only has Claude 4.x models. `claude-3-5-sonnet-20241022` returns 404. Correct model is `claude-sonnet-4-5-20250929` (verified via models endpoint)
- **4 GitHub issues created** for chat roadmap: #106 (expand context), #107 (child safety), #108 (hidden badge spoilers), #109 (stateless messages)

### Files Modified
- `composer.json` + 5 Passport migrations
- `config/auth.php` — added Passport `api` guard
- `app/Providers/AppServiceProvider.php` — Passport token expiry + auth view
- `routes/ai.php` — `Mcp::oauthRoutes()` + `auth:api,sanctum` middleware
- `routes/web.php` — OAuth login + callback routes, fixed SPA catch-all regex
- `app/Http/Controllers/Api/V1/GoogleAuthController.php` — `oauthLogin()` + `oauthCallback()` for session flow
- `resources/views/mcp/authorize.blade.php` — OAuth consent screen (published + customized)
- `config/services.php` — standardized `RESEND_API_KEY`, default Anthropic model
- `config/kinhold.php` — default Anthropic model
- `.env.example` — updated mail section
- `app/Services/ChatbotService.php` — `ai_mode` awareness in `resolveProvider()`
- `app/Http/Controllers/Api/V1/SettingsController.php` — `ai_mode` in GET/PUT response + validation
- `resources/js/views/settings/SettingsView.vue` — two-tab AI mode UI, `appOrigin` fix
- `resources/js/views/chat/ChatView.vue` — chat gate fix
- `resources/js/stores/chat.js` — normalize `{role,message}` → `{sender,text}`

---

## 2026-03-28 — Session 11: Settings Page Reorganization

### What Was Done
- **Settings page reorganized** into 6 collapsible sections (parent view) for better UX
  - Family, Tasks & Points, AI & Integrations, Feature Access, Appearance, Notifications
  - All sections start collapsed — click to expand what you need
  - Related settings grouped together (task points + task assignment + task access now in one section)
  - AI config + MCP token + calendar connections combined into "AI & Integrations"
  - Setup wizard relocated into the Family section
  - Tasks & Points consolidated to a single "Save Changes" button (was 3 separate saves)
- **Avatar permissions moved into Feature Access** — uses same Everyone/Parents Only/Off/Custom controls as other modules (was a standalone toggle in its own section)
- **Created `ToggleSwitch.vue`** reusable component — standardizes all toggle switches
  - Fixed avatar toggle inconsistency (was gold/smaller, now matches wisteria/standard size)
  - Proper ARIA `role="switch"` and `aria-checked` on all toggles
  - Supports `#thumb` slot for custom content (dark mode icons)
- **Created `SettingsSection.vue`** collapsible card component
  - Icon + title + description header with chevron indicator
  - `v-show` body preserves reactive form state when collapsed
  - URL hash deep-linking (e.g., `/settings#ai-integrations`)
  - Toned-down dark mode hover state
- **Fixed avatar bug** — parents editing a child's avatar would save to their own account instead
  - Backend now accepts `user_id` param on all avatar endpoints, verifies parent+same-family
  - Frontend passes `targetUser.id` in all AvatarEditor API calls
- **Created `docs/SETTINGS.md`** — documents storage map, component APIs, and how to add new settings
- Child view unchanged (stays flat — too few items for collapsible sections)

### Files Created
- `resources/js/components/common/ToggleSwitch.vue`
- `resources/js/components/settings/SettingsSection.vue`
- `docs/SETTINGS.md`

### Files Modified
- `resources/js/views/settings/SettingsView.vue` — full template restructure into collapsible sections
- `app/Http/Controllers/Api/V1/AuthController.php` — avatar target resolution for parent→child edits
- `resources/js/components/common/AvatarEditor.vue` — passes user_id in all API calls

---

## 2026-03-28 — Session 10: Profile Pictures & Avatars

### What Was Done
- **Profile pictures feature** (issue #18, PR #94) — full avatar management system
  - Photo upload via controller-served route (works on Upsun mounts)
  - 26 Phosphor icon presets across 5 categories (Animals, Nature, Space, Style, Vibes) — premium duotone weight
  - 12 brand-approved color picker from the design guide
  - Initials fallback with `@error` handling for broken images
  - `AvatarEditor.vue` modal: color picker, upload, preset grid, "Use Google Photo" restore, remove
  - `children_can_change_avatar` family setting (parent toggle)
- **Installed `@phosphor-icons/vue`** (MIT, tree-shakeable) — also unlocks richer badge icons later
- **Expanded `useFamilyColors`** to all 12 brand colors with user-selectable `avatar_color` column
- **Google avatar persistence** — `google_avatar` column stores Google photo URL permanently, refreshed on every OAuth login, "Use Google Photo" button in editor
- **Closed Phase 0 milestone** on GitHub (was 11/11 but still marked open)
- **Closed #91** — duplicate tag prevention already fixed in `edf099f`

### Files Created
- `resources/js/components/common/AvatarEditor.vue`
- `resources/js/components/common/avatarPresets.js`
- `database/migrations/2026_03_28_203832_add_avatar_color_to_users_table.php`
- `database/migrations/2026_03_28_211116_add_google_avatar_to_users_table.php`
- `public/.user.ini`

### Files Modified
- `app/Http/Controllers/Api/V1/AuthController.php` — 5 new methods (upload, delete, preset, serve, restoreGoogle) + helpers
- `app/Http/Controllers/Api/V1/GoogleAuthController.php` — saves google_avatar on all login paths
- `app/Http/Controllers/Api/V1/SettingsController.php` — children_can_change_avatar setting
- `app/Http/Resources/UserResource.php` — exposes avatar_color, google_avatar
- `app/Models/User.php` — avatar_color, google_avatar fillable
- `resources/js/components/common/UserAvatar.vue` — image/preset/initials priority chain with error fallback
- `resources/js/composables/useFamilyColors.js` — all 12 brand colors, user choice support
- `resources/js/stores/auth.js` — updateUserAvatar helper
- `resources/js/views/settings/SettingsView.vue` — avatar editor integration, parent toggle
- `routes/api.php` — 5 new avatar routes
- `.upsun/config.yaml` — storage:link in deploy hook
- `package.json` — @phosphor-icons/vue dependency

---

## 2026-03-28 — Session 9: Onboarding Wizard

### What Was Done
- **Built onboarding wizard** (issue #63) — 5-step guided setup for new families
  - Welcome (family name, timezone), Add Family (inline member creation), Calendar (Google OAuth), Tags (preset tag creation), Features (granular module access controls)
  - Simplified 3-step flow for joining members: Welcome → Calendar → Feature Explainer → Done
  - Feature explainer shows accessible features with descriptions, locked features greyed out
  - Router guard auto-redirects new users; existing users backfilled to skip
  - Re-triggerable from Settings > "Re-run Setup Wizard"
- **Closed Phase 0: Foundations milestone** — all 11 issues complete (100%)
  - Also closed #76 (Claude connector) — completed in Session 8 but left open
- **Created issue #89** — Remove task_list_id tech debt (tags-only organization)
- **Added `PATCH /api/v1/user`** endpoint for profile updates (timezone)
- **Updated CalendarController** — OAuth state now encodes origin for proper redirect back to wizard

### Files Created
- `app/Http/Controllers/Api/V1/OnboardingController.php`
- `database/migrations/..._add_onboarding_completed_at_to_users_table.php`
- `resources/js/stores/onboarding.js`
- `resources/js/views/onboarding/OnboardingView.vue`
- `resources/js/views/onboarding/steps/` — 7 step components (Welcome, Invite, Calendar, TaskList, Features, FeaturesExplainer, Complete)

### Files Modified
- `app/Models/User.php` — added `onboarding_completed_at` to fillable/casts
- `app/Http/Resources/UserResource.php` — exposes `onboarding_completed_at`
- `app/Http/Controllers/Api/V1/AuthController.php` — added `updateProfile` method
- `app/Http/Controllers/Api/V1/CalendarController.php` — origin param in OAuth state
- `app/Http/Controllers/Api/V1/FamilyController.php` — managed accounts auto-complete onboarding
- `resources/js/router/index.js` — onboarding route + guard
- `resources/js/App.vue` — hides sidebar/nav during wizard
- `resources/js/views/settings/SettingsView.vue` — "Re-run Setup Wizard" section
- `routes/api.php` — onboarding + user profile routes

---

## 2026-03-28 — Session 8: Laravel-Native MCP Server

### What Was Done
- **Replaced TypeScript MCP server with Laravel-native MCP** using `laravel/mcp` v0.6.4
  - Eliminated the separate Node.js process — MCP now runs directly in Laravel via `/mcp` endpoint
  - No HTTP round-trips: tools access Eloquent models and services directly
  - Auth via Sanctum bearer token (same token system, simpler setup)

- **Built 18 consolidated MCP tools** (down from 26 individual tools in the TypeScript server)
  - Each tool uses an `action` parameter to handle multiple operations, reducing schema/token overhead
  - All tools scoped to authenticated user's family via `ScopesToFamily` trait
  - Parent-only actions (deduct points, create rewards, manage vault) return errors for child users

- **Tool inventory:**
  - Family & Settings: `view-family`, `get-settings`, `search-family`
  - Tasks: `manage-task-lists`, `manage-tasks`, `complete-task`, `manage-tags`
  - Points & Rewards: `view-points`, `manage-points`, `manage-point-requests`, `manage-rewards`, `purchase-reward`
  - Badges & Events: `manage-badges`, `view-earned-badges`, `manage-featured-events`
  - Calendar & Vault: `view-calendar`, `manage-vault`, `manage-vault-access`

- **Full content coverage:** Points, rewards, badges, featured events, and settings now have MCP tools (previously 0% coverage)

### Files Created
- `routes/ai.php` — MCP route registration
- `app/Mcp/Servers/KinholdServer.php` — Main MCP server (18 tools)
- `app/Mcp/Tools/Concerns/ScopesToFamily.php` — Shared trait for user/family context
- `app/Mcp/Tools/ViewFamily.php`
- `app/Mcp/Tools/GetSettings.php`
- `app/Mcp/Tools/SearchFamily.php`
- `app/Mcp/Tools/ManageTaskLists.php`
- `app/Mcp/Tools/ManageTasks.php`
- `app/Mcp/Tools/CompleteTask.php`
- `app/Mcp/Tools/ManageTags.php`
- `app/Mcp/Tools/ViewPoints.php`
- `app/Mcp/Tools/ManagePoints.php`
- `app/Mcp/Tools/ManagePointRequests.php`
- `app/Mcp/Tools/ManageRewards.php`
- `app/Mcp/Tools/PurchaseReward.php`
- `app/Mcp/Tools/ManageBadges.php`
- `app/Mcp/Tools/ViewEarnedBadges.php`
- `app/Mcp/Tools/ManageFeaturedEvents.php`
- `app/Mcp/Tools/ViewCalendar.php`
- `app/Mcp/Tools/ManageVault.php`
- `app/Mcp/Tools/ManageVaultAccess.php`
- `.claude/commands/cleanup.md` — Post-merge cleanup command

### Files Modified
- `composer.json` — Added `laravel/mcp: ^0.6.4`

### Removed
- `mcp-server/` — Old TypeScript/Node.js MCP server (superseded by Laravel-native)

## 2026-03-17 — Session 7: Upsun Deployment & Google OAuth

### What Was Done
- **Deployed Kinhold to Upsun** at `family.kinhold.com`
  - Created project in Terra Nova org (project ID: `2rozcvqjtjdta`)
  - Connected to GitHub repo — pushes to `main` auto-deploy
  - Iterated on `.upsun/config.yaml` (php:8.4, n version manager, pdo_pgsql, Redis, storage mounts)
  - Created `.environment` file to map `PLATFORM_RELATIONSHIPS` to Laravel env vars
  - Set all production environment variables (APP_KEY, DB, Redis, session, Google OAuth, etc.)
  - Fixed multiple deployment issues: PHP version, bootstrap/cache permissions, POSIX shell compat, pdo_pgsql extension, storage:link on read-only fs

- **Created Greg's admin account on production** via SSH

- **Added Google OAuth login (Laravel Socialite)**
  - New `GoogleAuthController` with redirect + callback (3 cases: existing google_id, existing email, new user+family)
  - `config/services.php` with separate `GOOGLE_AUTH_REDIRECT_URI` for auth (vs calendar)
  - Migration: added `google_id` to users, made `password` nullable
  - Frontend: "Sign in with Google" / "Sign up with Google" buttons on LoginView + RegisterView
  - `auth.js` store: `initAuth()` picks up `?token=` from OAuth callback URL
  - Routes in `web.php` for `/auth/google/redirect` and `/auth/google/callback`

- **Fixed production bugs:**
  - CSRF token mismatch — set `SESSION_SECURE_COOKIE=true` and `SESSION_DOMAIN` on Upsun
  - Missing sessions table — created migration with `foreignUuid` (not `foreignId`)
  - Sessions table `user_id` type mismatch (bigint vs UUID) — fix migration for production
  - Settings 500 error — double-encoded JSON in family settings, fixed data on production
  - No logout button — added Sign Out button to `Sidebar.vue`
  - Google OAuth "missing client_id" — set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_AUTH_REDIRECT_URI` on Upsun

### Files Created
- `.environment` — Upsun platform relationship mapping
- `app/Http/Controllers/Api/V1/GoogleAuthController.php`
- `config/services.php`
- `database/migrations/2026_03_17_183542_create_sessions_table.php`
- `database/migrations/2026_03_17_184500_fix_sessions_user_id_to_uuid.php`
- `database/migrations/2026_03_17_185421_add_google_id_to_users_table.php`

### Files Modified
- `.upsun/config.yaml` — Rewrote for working Upsun deployment
- `app/Models/User.php` — Added `google_id` to fillable
- `resources/js/views/auth/LoginView.vue` — Google OAuth button
- `resources/js/views/auth/RegisterView.vue` — Google OAuth button
- `resources/js/stores/auth.js` — OAuth token pickup from URL
- `resources/js/components/layout/Sidebar.vue` — Sign Out button
- `routes/web.php` — Google OAuth routes
- `composer.json` — Added `laravel/socialite`

### Architecture Decisions
- Google OAuth callback redirects to `/login?token=xxx` for SPA to pick up via Sanctum token
- Separate `GOOGLE_AUTH_REDIRECT_URI` env var from calendar's `GOOGLE_REDIRECT_URI`
- Multi-family data isolation already supported (all tables scoped by `family_id`)

### Next Session TODO
- Verify Google OAuth works end-to-end on production (requires adding redirect URI in Google Cloud Console)
- Audit all controllers for family_id scoping before Corey signs up
- Dark mode toggle in TopBar
- End-to-end testing of gamification flow
- Continue UI/UX overhaul: Calendar, Dashboard

---

## 2026-03-17 — Session 6: Open Source Release & GitHub Push

### What Was Done
- **Verified parent-only access controls** — confirmed all sensitive UI buttons (badge creation, point deduction, reward management) are properly gated with `v-if="isParent"` across BadgesView, PointsFeedView, RewardsView, RewardCard, VaultCategoriesView, VaultEntriesView, DashboardView
- **Captured 4 dark mode screenshots** for README using Playwright headless Chromium — points feed, badges, rewards, tasks (saved to `docs/screenshots/`)
- **Rewrote README.md** for open-source release — professional formatting with features, screenshots, tech stack, quick start (native + Docker), demo accounts, API routes, MCP server docs, contributing guide, roadmap link
- **Expanded `.env.example`** — full template with all config vars, no secrets
- **Updated `.gitignore`** — added vendor, Laravel cache/session/view paths, .claude/, session captures, test-results
- **Created initial git commit** — 207 files, 31,838 insertions
- **Pushed to GitHub** — public repo at https://github.com/gregqualls/kinhold
  - `gh repo create kinhold --public --source . --push`

### Next Session TODO
- Deploy to Upsun for personal/family use (plan documented below in Session 6 notes)
- Dark mode toggle in TopBar (still pending)
- End-to-end testing of gamification flow
- Test recurring task generation
- Continue UI/UX overhaul: Calendar components, Dashboard enhancements

---

## 2026-03-17 — Session 5: Gamification System (Points, Rewards, Badges)

### What Was Done
- **Full gamification system implemented** across ~50 new/modified files covering backend, frontend, and integration.

- **Backend — Migrations (6 new):**
  - `add_recurrence_to_tasks_table` — points, recurrence_rule, recurrence_end, parent_task_id
  - `create_point_transactions_table` — ledger-based points system with polymorphic source
  - `create_rewards_table` — parent-created prizes purchasable with points
  - `create_reward_purchases_table` — purchase history
  - `create_badges_table` — Steam-style achievements with trigger types
  - `create_user_badges_table` — pivot with earned_at and awarded_by

- **Backend — Enums (2 new):**
  - `PointTransactionType` — task_completion, task_reversal, kudos, deduction, redemption, adjustment
  - `BadgeTriggerType` — points_earned, tasks_completed, task_streak, kudos_received/given, rewards_purchased, login_streak, custom

- **Backend — Models (4 new, 3 updated):**
  - New: PointTransaction, Reward, RewardPurchase, Badge
  - Updated: Task (points, recurrence, getEffectivePoints), User (pointBank, badges), Family (leaderboard period, enabled modules)

- **Backend — Services (2 new):**
  - `PointsService` — award/reverse task points, kudos, deductions, reward redemption, leaderboard with period-scoped rankings
  - `BadgeService` — auto-checks badge thresholds after events, manual award/revoke, streak calculations

- **Backend — Controllers (3 new, 1 updated):**
  - New: PointsController (bank, leaderboard, feed, kudos, deduct), RewardsController (CRUD + purchase), BadgesController (CRUD + award/revoke + progress)
  - Updated: TaskController — awards points on complete, reverses on uncomplete, checks badges

- **Backend — Recurring Tasks:**
  - `GenerateRecurringTasks` artisan command — parses RRULE (DAILY, WEEKLY+BYDAY, MONTHLY+BYMONTHDAY), generates 7 days ahead
  - Scheduled daily at 00:05 via Kernel

- **Backend — Feature Toggles:**
  - SettingsController accepts enabled_modules + leaderboard_period
  - Stored in families.settings JSON column

- **Frontend — Pinia Stores (2 new, 1 updated):**
  - `stores/points.js` — bank, leaderboard, feed, rewards, purchases with all CRUD actions
  - `stores/badges.js` — badges, earned badges with CRUD + award/revoke
  - `stores/auth.js` — added enabledModules and isModuleEnabled computed

- **Frontend — Points Views & Components (3 views, 6 components):**
  - PointsFeedView — balance card, leaderboard strip, scrollable activity feed, kudos input, deduct modal
  - RewardsView — reward grid with purchase flow, parent CRUD
  - PointsHistoryView — personal transaction history
  - Components: LeaderboardStrip, FeedItem, KudosInput, DeductModal, RewardCard, TaskPointsBadge

- **Frontend — Badges Views & Components (1 view, 5 components):**
  - BadgesView — All/Earned/Locked tabs, create badge form, icon picker, manual award
  - Components: BadgeIcon (hexagonal SVG), BadgeCard, BadgeShowcase, BadgeProgressBar, badgeIcons.js (20 SVG paths)

- **Frontend — Integration:**
  - Sidebar + BottomNav — Points and Badges nav items, filtered by enabled modules
  - TopBar — page titles for all new views
  - DashboardView — Points balance card + LeaderboardStrip, Badges showcase
  - Router — /points, /points/rewards, /points/history, /badges with module guards
  - SettingsView — module toggles for points/badges, leaderboard period selector

- **Seeder — Demo Data:**
  - 5 rewards (Sweets 10pts, TV Time 20pts, Pick Dinner 30pts, Movie Pick 40pts, Stay Up Late 75pts)
  - Tasks with point values + recurring "Take out trash" every Tuesday
  - 7 point transactions (Demo Child has 45 pts in bank)
  - 11 badges (10 preset + 1 custom "Welcome"), 2 earned by Demo Child
  - Family settings with all modules enabled + weekly leaderboard

### Architecture Decisions
- **Ledger pattern** for points: Point Bank = SUM(all transactions), Leaderboard = SUM(positive in current period). Never mutate a running balance — always append transactions.
- **Instant purchases** — no approval flow for rewards. Points deducted immediately.
- **Hidden badges** show as "???" until earned — fun surprise mechanic for kids.
- **Feature toggles** stored in family settings JSON, enforced at nav/router/API level.

### Build Status
- 791 Vue/JS modules, 0 errors via `npx vite build`
- All 17 migrations run successfully
- Seeder creates full demo data (verified: 2 users, 5 tasks, 5 rewards, 11 badges, 7 transactions, 2 earned badges, child bank = 45 pts)

### Next Session TODO
- Dark mode toggle in TopBar (still pending from Session 4)
- Test the full flow end-to-end in browser (complete task → points awarded → badge earned → toast)
- Test recurring task generation: `php artisan app:generate-recurring-tasks`
- Test feature toggles: disable points/badges → verify nav/routes hidden

---

## 2026-03-17 — Session 4: Dark Mode & CSS Architecture Fix

### What Was Done
- **Fixed dark mode CSS architecture:**
  - Root cause: global dark mode overrides in `app.css` were outside `@layer`, making them always beat Tailwind's `dark:` utilities (which live inside `@layer utilities`). This meant all explicit `dark:` classes were being silently ignored.
  - Moved dark mode overrides into `@layer components` so they serve as defaults that Tailwind's `dark:` utilities can properly override.
  - Removed blunt global overrides (`.dark .bg-white`, `.dark .text-prussian-500`, `.dark .bg-lavender-50`, `.dark .border-lavender-200`, etc.) that were masking everything.
  - Kept component-level dark overrides (`.dark .card`, `.dark .input-base`, `.dark .btn-secondary`, `.dark .btn-ghost`, `.dark .divider`) as sensible defaults in `@layer components`.

- **Added dark mode variants to SettingsView.vue:**
  - All 6 section headings now have `dark:text-lavender-200`
  - All labels, descriptions, helper text have `dark:text-lavender-300` or `dark:text-lavender-400`
  - All list items (`bg-lavender-50`) have `dark:bg-prussian-700`
  - All borders have `dark:border-prussian-700`
  - Error/info banners have dark variants (`dark:bg-red-900/20`, `dark:bg-blue-900/20`)

- **Improved task save button UX (TaskDetailPanel.vue):**
  - Added dirty state tracking (`isDirty` computed) that compares form values against original snapshot
  - "Unsaved changes" label (gold text) appears when any field is modified
  - Save button gets subtle glow + scale-up when dirty to draw attention
  - "Saved!" confirmation with green checkmark flashes for 2 seconds after saving
  - Fixed invalid Tailwind class `dark:bg-prussian-700.5` (appeared on date and select inputs)

- **Added calendar source labels (CalendarView.vue):**
  - Added `calendarNameMap` computed and `getCalendarSourceName()` helper
  - Month view: tooltip on event chips shows calendar source
  - Week view: small text line under each event shows source name
  - Day view: colored dot + label shows calendar source

- **Dark mode fixes across remaining components:**
  - DashboardView: Quick Actions heading, task text, completed task styles
  - TaskItem: due date classes returned from JS now include `dark:` variants
  - TopBar: avatar ring color (`dark:ring-prussian-800`)

- **Discovered stale Vite dev server issue:**
  - The Vite dev server had been running since session start, consuming 2245 min CPU, and was NOT generating custom color Tailwind utilities
  - Restarting it fixed CSS generation — all `dark:bg-prussian-*`, `dark:text-lavender-*` etc. now properly generated
  - Important: if dark mode appears broken, restart the Vite dev server first (`kill` the old process, then `npm run dev`)

### Build Status
- 774 Vue/JS modules, 0 errors via `npx vite build`
- Dark mode verified working in browser — cards, headings, borders, inputs all correct
- Light mode verified still working correctly

### Next Session TODO
- **Add dark mode toggle to TopBar** (desktop) and mobile header — currently only accessible via Settings > Appearance
- **Update ROADMAP.md** — Dark mode status should change from DEFERRED to IN PROGRESS/COMPLETE
- Continue with Phase 3 (Calendar) or Phase 5 (Dashboard) from the UI/UX overhaul plan

---

## 2026-03-16 — Session 3: UI/UX Overhaul (Phases 1-2-4-6)

### What Was Done
- **Phase 1 — Shared UI Components:**
  - New `ConfirmDialog.vue` — Destructive action confirmation with loading state
  - New `ContextMenu.vue` — Dropdown menus with actions, dividers, danger variants
  - New `SlidePanel.vue` — Right-side slide-over panel for detail editing
  - New `FloatingActionButton.vue` — Mobile FAB for primary create actions
  - New `UndoToast.vue` — Undo-able toast notifications with auto-dismiss
  - Updated `UserAvatar.vue` — Added `xs` size for inline use
  - Updated `App.vue` — Page transitions, polished toast notifications, removed stale auth loading overlay
  - Updated `Sidebar.vue` — Q logo, cleaner nav with active states, user role display
  - Updated `BottomNav.vue` — Solid/outline icon switching for active tab, frosted glass background
  - Updated `TopBar.vue` — Simplified, overlapping avatar stack
  - New CSS animations — scale transitions, checkbox bounce, task list transitions

- **Phase 2 — Tasks (Todoist-inspired):**
  - Complete rewrite of `TaskListsView.vue`:
    - Today / Upcoming smart view cards
    - Task list rows with colored icons, progress rings, task counts
    - Context menu on each list (Edit / Delete)
    - Create + Edit list modals with color picker
    - Delete confirmation dialog
    - Mobile FAB + desktop "New List" button
  - Complete rewrite of `TaskListDetailView.vue`:
    - Inline quick-add input (Task name + Date + Priority cycling)
    - Animated circle checkboxes colored by priority (red=high, orange=medium, gray=low)
    - All/Priority filter tabs with live counts
    - Task detail slide panel (edit title, description, priority, due date, assignee, completion toggle)
    - Delete task confirmation
    - Undo toast on task completion
    - Edit/Delete list from within the detail view
    - Collapsible completed tasks section
  - New task components: `TaskCheckbox.vue`, `TaskItem.vue`, `TaskQuickAdd.vue`, `TaskDetailPanel.vue`

- **Phase 4 — Vault (1Password-inspired):**
  - Rewrite of `VaultCategoriesView.vue` — Search bar, category cards with colored icons, "Add Entry" modal with dynamic key-value fields
  - Rewrite of `VaultEntriesView.vue` — Search, entry list with context menus, delete confirmation
  - Rewrite of `VaultEntryView.vue` — Data fields with SensitiveField component, documents, permissions, metadata
  - New `SensitiveField.vue` — Eye toggle reveal, one-click copy with auto-clear clipboard (30s), auto-hide on tab blur

- **Phase 6 — Chat (Polish):**
  - Rewrite of `ChatView.vue` — Message bubbles (user=right/blue, AI=left/gray), animated typing indicator (bouncing dots), suggested question cards, fixed bottom input bar

- **Bug Fixes:**
  - Fixed auth `isLoading` overlay staying visible during background `fetchUser()` calls
  - Fixed `createTask` using wrong API endpoint (`POST /tasks` → `POST /task-lists/{id}/tasks`)
  - Fixed `toggleComplete` using wrong endpoint (`/toggle-complete` → `/complete` or `/uncomplete`)
  - Fixed `fetchTasks` not loading `taskLists` when navigating directly to a task list detail page
  - Fixed `currentList` not resolving when `taskLists` array was empty on direct navigation

### Build Status
- 772 Vue/JS modules, 0 errors via `npx vite build`
- All pages verified in browser (mobile + desktop viewports)
- Task CRUD fully functional: create, edit, complete, delete tasks and task lists

## 2026-03-16 — Session 1: Project Scaffolding

### What Was Done
- **Project kickoff:** 5 rounds of design questions to nail down MVP scope, tech stack, and architecture
- **Full project scaffolding:** 146 files across backend, frontend, MCP server, and infrastructure
- **Backend (Laravel 11):**
  - 9 Eloquent models with full relationships (User, Family, Task, TaskList, VaultEntry, VaultCategory, VaultPermission, Document, CalendarConnection)
  - 3 backed enums (FamilyRole, TaskPriority, PermissionLevel)
  - 10 database migrations with proper foreign keys and indexes
  - 9 API controllers with CRUD operations
  - 6 form request validators
  - 8 API resource transformers
  - 4 authorization policies
  - 3 service classes (GoogleCalendar, VaultEncryption, Chatbot)
  - 2 database seeders (vault categories + demo family)
  - Full route configuration with Sanctum middleware
- **Frontend (Vue 3 SPA):**
  - 5 Pinia stores (auth, tasks, vault, calendar, chat)
  - Vue Router with auth guards (guest, authenticated, parent-only)
  - 7 common components (BaseCard, BaseButton, BaseModal, BaseInput, UserAvatar, EmptyState, LoadingSpinner)
  - 3 layout components (BottomNav, Sidebar, TopBar)
  - 9 page views (Login, Register, Dashboard, Calendar, TaskLists, TaskDetail, VaultCategories, VaultEntries, VaultEntry, Chat, Settings)
  - Module-specific components for calendar, tasks, vault, and chat
  - API service with Axios (CSRF, auth, error handling)
  - 2 composables (useNotification, useFamilyColors)
  - Tailwind CSS with custom warm color palette
- **MCP Server:**
  - TypeScript with @modelcontextprotocol/sdk
  - 26 tools across 5 categories (tasks, calendar, vault, family, chat)
  - API client with Sanctum token auth
- **Infrastructure:**
  - Docker Compose with app, nginx, PostgreSQL, Redis, node services
  - Multi-stage Dockerfile
  - Nginx configuration
  - Upsun deployment config (`.upsun/config.yaml`)
  - Setup script (`setup.sh`)
- **Documentation:**
  - CLAUDE.md (project context for future sessions)
  - docs/ARCHITECTURE.md (technical decisions with reasoning)
  - docs/ROADMAP.md (4-phase feature plan)
  - docs/CONVENTIONS.md (coding standards)
  - CHANGELOG.md (this file)
  - README.md (open-source project readme)
  - MIT LICENSE

### Decisions Made
- Laravel 11 REST API + Vue 3 SPA (not Livewire/Inertia)
- PostgreSQL over MySQL (better JSON, full-text search)
- App-level encryption for vault (not per-user or zero-knowledge — enables chatbot)
- Hybrid vault permissions (parent/child roles + per-item overrides)
- MCP server in TypeScript (better SDK support)
- Mobile-first card-based UI with bottom navigation
- Cookie auth for SPA, token auth for MCP
- Open source: Elastic License 2.0, Docker + self-hosted deployment

### Bug Fixes Applied (same session)
- Fixed CSS import path in app.js (`@/css/app.css` → `../css/app.css`)
- Fixed `creator_id` → `created_by` in TaskController, TaskListController, VaultController, TaskPolicy
- Fixed AuthController to use direct `family_id` assignment instead of non-existent pivot table
- Added `currentFamily()` query builder method to User model
- Created missing ChatMessage model + migration
- Fixed ChatbotService to use HTTP client instead of non-existent Anthropic PHP SDK
- Removed non-existent CalendarEvent model reference from ChatbotService
- Added `invite_code` column to families migration and Family model fillable
- Fixed CalendarController field names (`color_code` → `color`, removed `calendar_email`)
- Fixed Document creation in VaultController to use polymorphic fields correctly
- Fixed Dockerfile (`vite.config.ts` → `vite.config.js`, added `php` stage name, added `icu-dev`)
- Improved setup.sh with better error handling and Docker Compose v2 support
- Simplified entrypoint.sh (removed non-existent artisan commands)
- Frontend builds clean: 431 modules, 0 errors

### Known Issues / Next Steps
- [ ] Need Docker on local machine to boot (`chmod +x setup.sh && ./setup.sh`)
- [ ] Google Calendar OAuth needs real credentials from Google Cloud Console
- [ ] Chatbot needs Anthropic API key in `.env`
- [ ] Route conflict possible: `/vault/entry/:id` vs `/vault/:categorySlug` — needs runtime testing
- [ ] Some Vue components reference `@heroicons/vue` which may need icon adjustments
- [ ] `CalendarEventResource` receives arrays not models — may need adjustment
- [ ] Vault encryption service needs testing with actual encrypted data round-trips
