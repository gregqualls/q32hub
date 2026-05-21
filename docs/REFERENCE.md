# Kinhold — Technical Reference

> Detailed module state, database schema, API routes, and file layout.
> CLAUDE.md is the slim briefing; this is the deep dive. Read this when you need specifics.

## Modules — Current State

### 1. Authentication (MVP — SCAFFOLDED)
- Email/password with Sanctum
- Family creation on registration OR join via invite code
- Roles: `parent` (full access) and `child` (restricted)
- Google OAuth login via Laravel Socialite (redirect → callback → Sanctum token → SPA pickup)
- Email verification sent on registration; dismissable banner for unverified users; existing users grandfathered
- Google account linking from Settings or on Google sign-in attempt (password confirmation)
- **Future:** passkeys, 2FA

### 2. Family Calendar (IMPLEMENTED — Unified Events)
- **Unified event model:** `FeaturedEvent` and `FamilyEvent` merged into single `family_events` table. Any calendar event can optionally be "featured" on the dashboard (personal or family scope).
- **Manual events:** Full CRUD from calendar UI. "Add Event" button, click-a-day to pre-fill, click-to-edit. Title, date/time, all-day, end time, location, color.
- **Recurrence:** Weekly/monthly/yearly events expand into all occurrences within the view's date range via `occurrencesInRange()`.
- **Visibility:** `visible` (full details), `busy` (others see "Busy"), or `private` (creator only). Enforced at API + MCP.
- **Featured scope:** `personal` (just creator's dashboard) or `family` (everyone's dashboard). Parent-only to set.
- **Countdown banner:** Dismiss persists in localStorage, auto-hides past events, parent management actions.
- **Source styling:** Tasks = dashed amber borders, manual = solid colored, Google/ICS = calendar connection colors.
- **View mode persistence:** Month/week/day selection saved in localStorage.
- Google Calendar + ICS aggregation works alongside manual events. Tasks with due dates appear as all-day events.
- **Note:** `FeaturedEvent` model/table still exists but is deprecated — all new code uses `FamilyEvent`.
- **Future:** Two-way Google sync, more providers (Outlook, iCloud), drop `featured_events` table.

### 3. Tasks & To-Dos (MVP — SCAFFOLDED + GAMIFICATION)
- Task lists (e.g., "Grocery", "House Projects", "School")
- Tasks with: title, description, assignee, due date, priority (low/medium/high), points
- Family tasks (anyone can complete) vs personal tasks
- Recurring tasks via RRULE (daily, weekly+day, monthly+date) — artisan command generates instances 7 days ahead
- Points awarded on completion, reversed on uncomplete
- **Future:** Categories, kanban boards, subtasks, dependencies

### 4. Family Vault (IMPLEMENTED — Markdown + Sensitive Fields)
- **WYSIWYG markdown editor** (Milkdown) for entry content — headings, bold, italic, lists, code, blockquotes
- Optional **sensitive fields** section for passwords, SSNs, account numbers (masked, tap to reveal, auto-clear clipboard)
- Custom categories (CRUD) with 10 icon options. Defaults: Medical, Financial, Insurance, Legal, Education, Personal
- **Kids personal vault:** `is_personal` flag — children can create/edit/delete their own entries. Parents see everything.
- Document uploads (PDFs, images) with download
- **Permissions model:** Parent/child base roles + per-item overrides (view/edit). Share UI with family member dropdown.
- **Vault playbooks:** 5 `.md` skill files in `playbooks/vault/` for AI-guided data entry (house manual, medical, vehicle, school, emergency). Community-contributable via PR.
- Data format: `encrypted_data` stores `{ body: "markdown", sensitive_fields: { key: value } }`. Legacy flat key/value format has fallback rendering.
- **Future:** RAG search tool, version history, audit log

### 5. AI Assistant (IMPLEMENTED — Agent Architecture)
- Natural language interface to the 7 consolidated MCP tools via Claude's tool_use API
- `AgentService` orchestrates: user message → Claude decides tool calls → `ToolRegistry` executes → results fed back → loop until text response (max 10 iterations)
- **Hallucination guard:** Server-side `claimsAction()` check rejects responses claiming actions taken without tool calls. Logs warning and forces retry.
- Safety guardrails: tool-only scope, no off-topic, no prompt injection, no physical tasks. Asks clarifying questions for incomplete requests.
- Markdown rendering in assistant responses (marked + DOMPurify for XSS safety)
- Only Anthropic supported for agent mode (tool_use is provider-specific). BYOK + platform key modes both work.
- **Per-family daily message cap** (hosted instances on the platform key only). `AiUsageService` enforces a hard cap with a 429 lockout and surfaces a usage chip + lockout panel in `ChatView.vue`. Plans live in `config('kinhold.chatbot.plans')` as `slug → { name, daily_messages, price_monthly_cents, stripe_price_id, public }`. Family stores its plan slug at `families.settings.chatbot.plan`. Numeric override available at `families.settings.chatbot.daily_message_limit` (admin/support escape hatch). BYOK families and `config('kinhold.self_hosted')` instances bypass automatically. Demo family resolves to the `demo_plan` slug for a richer baseline. Token usage (input + output, including cache hits) is captured per turn in `ai_usage_daily` and per assistant message in `chat_messages.metadata` — captured but **not** enforced in v1.
- **Future:** RAG for vault data, proactive reminders, multi-step workflows, Stripe-driven plan switcher (#70), token-budget enforcement, 80% warning toast (skipped in v1 — chip color carries the signal)

### 6. MCP Server (IMPLEMENTED — Laravel-Native)
- Laravel-native via `laravel/mcp` package — runs at `/mcp` endpoint, no separate process
- **7 domain-router tools** (consolidated from 20, April 2026): `kinhold-family`, `kinhold-calendar`, `kinhold-tasks`, `kinhold-food`, `kinhold-points`, `kinhold-vault`, `kinhold-achievements`. Each takes an `action` enum that dispatches to per-action handlers (e.g. `kinhold-tasks` accepts `task_list`, `task_create`, `tag_list`, etc.). Action sets and required params are documented in each tool's `#[Description]`.
- **Module-gated registration** — every tool except `kinhold-family` implements `Concerns\RequiresModule`, which uses `Family::userHasModuleAccess()` in `shouldRegister()` to skip registration when the family has the matching module disabled. Unused-module tool schemas never hit the wire.
- Authenticates with Passport OAuth or Sanctum bearer (`auth:api,sanctum` middleware).
- Direct model/service access (no HTTP round-trips). MCP tools delegate to the same service classes as the API controllers (`PointsService`, `MealPlanService`, `RecipeService`, etc.) so business logic stays single-sourced.
- `Concerns\ScopesToFamily` trait scopes all queries to authenticated user's family.
- Parent-only write actions enforced at tool level via `requireParent()` or `authorize('ability', $model)` (delegates to Laravel Policies).
- **Photo uploads** (recipes, restaurants) are not supported via MCP — multipart isn't available in the protocol. URL-based imports work (`recipe_import_url`, `restaurant_import`); photo upload still requires the API directly.
- **Future:** Anthropic Tool Search / deferred-loading support once `laravel/mcp` ships it upstream — would let heavy domain tools (food, vault, points) defer their schemas until the LLM searches for them, layering on top of module gating.

### 7. Points & Gamification (IMPLEMENTED)
- **Ledger-based:** Point Bank = SUM(all transactions). Never mutate a balance — append transactions.
- Transaction types: task_completion, task_reversal, kudos, deduction, redemption, adjustment
- Kudos: any family member can give +1 pt with a reason
- Deductions: parents only, negative points with reason
- **Leaderboard:** Ranked by positive points within configurable period (daily/weekly/monthly). Resets each period. Does NOT affect the bank.
- **Rewards store:** Parent-created prizes with marketplace features: limited stock, expiration dates, visibility controls (everyone/parent-only/child-only/specific people/age range), search/filter/sort. Reusable RewardForm component.
- **Auction system:** Two modes — timed (auto-resolve via `rewards:resolve-auctions` command) and parent-called. Points held on bid, released when outbid/cancelled, converted to purchase on win. One bid per user per auction. `AuctionService` with DB transaction locking.
- **Activity feed:** Family-wide scrollable feed showing all point events
- **Feature toggles:** Parents can enable/disable points and badges modules in Settings

### 8. Badges (IMPLEMENTED)
- Steam-style achievements — purely for fun
- Auto-triggered by thresholds: points_earned, tasks_completed, task_streak, kudos_received/given, rewards_purchased, login_streak
- Custom badges: manually awarded by parents
- Hidden badges: show as "???" until earned
- Hexagonal badge icons with accent color glow, 20 preset SVG icons
- 10 preset badges seeded per family + parents can create more
- BadgeService checks thresholds after every point/task event

### 9. Food & Meal Planning (IMPLEMENTED — All 8 Steps Complete + v1.10.0)
- Recipe backend + module gating (Step 1, Issue #148)
- Recipe import service: URL scraping + photo AI (Step 2, Issue #149)
- Recipe frontend UI (Step 3, PR #158)
- Shopping backend + product catalog (Step 4, PR #159)
- Shopping frontend + UX (Step 5, PR #162)
- Meal plan backend live pipeline (Step 6, PR #165)
- Meal plan frontend: weekly calendar, restaurants tab, settings (Step 7, Session 35)
- **Step 8 (April 2026):** MCP tools for food/meals delivered via `kinhold-food` (47 actions across recipes, shopping, meal plans, presets, restaurants). Closes Issue #155 + #67.
- **v1.10.0 (May 2026):** Six-PR allergen + sharing release. See module 10 below.
- See `docs/FOOD-FEATURES-SPEC.md`, `docs/FOOD-IMPLEMENTATION-PLAN.md`, and `docs/ALLERGENS-IMPLEMENTATION-PLAN.md`

### 10. Allergens, Multi-image, & Public Sharing (IMPLEMENTED — v1.10.0)
Severe-stakes (anaphylaxis-grade) allergen system layered on top of the Food module. Six sub-PRs:

- **Allergen reference data:** Big 9 (milk, eggs, fish, shellfish, tree-nuts, peanuts, wheat, soy, sesame) seeded globally with `family_id = null`; families can add custom allergens. Parent-only mutations.
- **Per-member allergy profile:** `users.allergen_profile_reviewed_at` timestamp drives a dashboard prompt until each member's profile is explicitly reviewed. Parents may edit anyone; members only their own.
- **Recipe allergen tagging:** `recipe_allergens` pivot with `(contains, may_contain)` presence + `(ai_auto, ai_suggested, human_confirmed, imported)` source + confidence. Same allergen can appear at both presence levels on one recipe.
- **Filtering + planner safety:** `GET /recipes?safe_for_members[]=<id>` (or `safe_for=all`) excludes recipes carrying any allergen for the given reviewed-profile members. Unreviewed profiles silently skipped — no false-safe filtering. Meal-plan add_entry refuses with 409 + `requires_acknowledgement` unless caller sends `acknowledge_allergens: true`.
- **AI integration:** `RecipeImportService` URL/photo prompts dynamically include the family's allergen slug list and extract `allergens: [{slug, presence, confidence}]`. Confidence ≥ 0.95 → `ai_auto`, else `ai_suggested`. One-click confirm on the recipe detail flips a row to `human_confirmed`. Backfill via `php artisan recipes:backfill-allergens` (or `POST /recipes/allergens/backfill`, parent-only, 2/day throttle); worker-level `RateLimited` cap of 30 Anthropic calls/min per family. Job takes both `recipeId` and `familyId` and asserts match.
- **Multi-image recipes:** `recipe_images` table (UUID, sort_order, is_primary). Backfilled from `recipes.image_path` which stays as a denormalized cache of the primary's path so card readers don't break. `RecipeImageGallery` Vue component: drag-reorder, tap-primary, delete, multi-file upload.
- **Public sharing:** `recipes.share_token` (unguessable 22-char base62, ~131 bits) + `recipes.share_visible_attribution` (anonymous by default). Public web route `GET /r/{token}` renders a dedicated Blade view with OG/Twitter cards, full-bleed hero + gradient transition, fraction-formatted ingredients, line-art allergen icons, and a Print button (dedicated print stylesheet). Revoke clears the token; old URLs hard-404. `<meta name="robots" content="noindex">` so link leaks don't reach crawlers.

Authorization shape (in addition to existing recipe Policy):
- `AllergenPolicy` — parents create/rename/delete family customs; Big 9 immutable for everyone
- `UserAllergenPolicy` — view is family-scoped; edit is parent OR self
- Mass-assignment hardened: `share_token`, `share_visible_attribution`, `allergen_profile_reviewed_at` are NOT in `$fillable`; writers go through `forceFill`

PWA: `/^\/r\//` added to `navigateFallbackDenylist` so the service worker passes share URLs through to the server.

MCP coverage: 13 new actions in `kinhold-food` covering allergens, per-member profiles, recipe-allergen single-row edits, backfill, and sharing. `recipe_list` accepts `safe_for_members[]` + `safe_for`. `meal_plan_add_entry` accepts `acknowledge_allergens` and surfaces `{requires_acknowledgement, hits}` when blocked.

## Database Schema (Key Tables)

- `families` — id, name, slug, invite_code, settings (JSON)
- `users` — id, family_id, name, email, password, family_role (enum), avatar, avatar_color, google_avatar, date_of_birth, timezone
- `tasks` — id, family_id, created_by, assigned_to, title, description, due_date, completed_at, priority (enum), is_family_task, points, recurrence_rule, recurrence_end, parent_task_id
- `vault_categories` — id, family_id, name, slug, icon, description
- `vault_entries` — id, family_id, vault_category_id, created_by, title, encrypted_data (encrypted JSON), notes, metadata (JSON)
- `vault_permissions` — id, vault_entry_id, user_id, permission_level (enum: view/edit)
- `documents` — id, documentable_type/id (polymorphic), uploaded_by, original_filename, stored_filename, mime_type, size, disk, path, encrypted
- `calendar_connections` — id, user_id, provider, access_token (encrypted), refresh_token (encrypted), calendar_id, color, is_active
- `chat_messages` — id, user_id, family_id, message, role (user/assistant), metadata (JSON: tools_used, input_tokens, output_tokens)
- `ai_usage_daily` — id, family_id, date, message_count, input_tokens, output_tokens. UNIQUE(family_id, date). One row per family per active day; daily message-count cap is enforced against this row.
- `family_storage_usages` — id, family_id (unique), total_bytes, reported_bytes, last_calculated_at, last_reported_at. One row per family. `total_bytes` is the live sum of `documents.size` joined through every polymorphic owner that ladders up to a family (today: VaultEntry only). `reported_bytes` is the last absolute total pushed to Stripe so the nightly tally pushes only deltas (Stripe meter events are additive). Kept warm in real time by `Document::created`/`deleted` hooks; fully recomputed nightly by `kinhold:tally-storage` (#216 / 70-C).
- `point_transactions` — id, family_id, user_id, type (enum), points (int), description, source_type/id (polymorphic), awarded_by
- `rewards` — id, family_id, created_by, title, description, point_cost, icon, quantity, quantity_purchased, expires_at, visibility (enum), visible_to (JSON), min_age, max_age, reward_type (enum: standard/auction), min_bid, bid_start_at, bid_end_at, is_active, sort_order
- `reward_purchases` — id, family_id, reward_id, user_id, points_spent, purchased_at
- `reward_bids` — id (UUID), family_id, reward_id, user_id, bid_amount, held_points, is_winning, resolved_at (unique: reward_id+user_id)
- `badges` — id, family_id, created_by, name, description, icon, color, trigger_type, trigger_threshold, is_hidden, is_active
- `user_badges` — id, user_id, badge_id, earned_at, awarded_by (unique: user_id+badge_id)
- `allergens` — id (UUID), family_id (nullable: null = global Big 9), name, slug, is_big_nine (bool). Unique (family_id, slug).
- `member_allergens` — id (UUID), user_id, allergen_id (unique pair). UUID-keyed via `MemberAllergen` pivot.
- `recipe_allergens` — id (UUID), recipe_id, allergen_id, presence (enum: contains/may_contain), source (enum: ai_auto/ai_suggested/human_confirmed/imported), confidence (decimal 0-1, nullable), confirmed_by, confirmed_at. Unique (recipe_id, allergen_id, presence). UUID-keyed via `RecipeAllergen` pivot.
- `recipe_images` — id (UUID), recipe_id, path, sort_order, is_primary. `recipes.image_path` is a denormalized cache of the primary's path.
- `users.allergen_profile_reviewed_at` (timestamp, nullable) — drives the dashboard "set up your allergy profile" prompt.
- `recipes.share_token` (string, nullable, unique) + `recipes.share_visible_attribution` (bool) — public sharing state.

## API Route Map

All routes prefixed with `/api/v1/`. Auth routes are public; everything else requires Sanctum auth.

- **Auth:** `POST /register`, `POST /login`, `POST /logout`, `GET /user`
- **Tasks:** CRUD on `/tasks` and `/task-lists`, plus `PATCH /tasks/{id}/toggle`
- **Vault:** CRUD on `/vault/entries` and `/vault/categories`, permissions at `/vault/entries/{id}/permissions`, documents at `/vault/entries/{id}/documents`
- **Calendar:** `GET /calendar/events`, `GET /calendar/connections`, `POST /calendar/connect`, `POST /calendar/sync`
- **Family:** `GET /family`, `GET /family/members`, `POST /family/invite`, `PUT /family/settings`
- **Chat:** `POST /chat`, `GET /chat/history`
- **Settings:** `GET /settings`, `PUT /settings`, `POST /settings/ai/test`
- **Points:** `GET /points/bank`, `GET /points/leaderboard`, `GET /points/feed`, `POST /points/kudos`, `POST /points/deduct`
- **Rewards:** CRUD on `/rewards`, `POST /rewards/{id}/purchase`, `GET /rewards/purchases`
- **Badges:** CRUD on `/badges`, `POST /badges/{id}/award`, `DELETE /badges/{id}/revoke/{user}`, `GET /badges/earned`
- **Allergens (module: food):** CRUD on `/allergens` (parent-only mutations; Big 9 immutable). Member profile at `/users/{user}/allergens` (GET / PUT / POST mark-reviewed). Recipe-allergen single-row at `/recipes/{recipe}/allergens` (POST add) and `/recipes/{recipe}/allergens/{allergen}` (PATCH confirm / change / remove). Family backfill at `POST /recipes/allergens/backfill` (parent-only, 2/day throttle).
- **Recipe sharing (module: food, parent-only):** `POST /recipes/{recipe}/share` (publish), `PATCH /recipes/{recipe}/share` (toggle attribution), `DELETE /recipes/{recipe}/share` (revoke). Public render at `GET /r/{token}` (web route, no auth).
- **Recipe filtering:** `GET /recipes` now accepts `?safe_for_members[]=<id>` (one or more) or `?safe_for=all` to exclude recipes carrying allergens for those reviewed-profile members. Unreviewed profiles are silently skipped.
- **Config:** `GET /config` (public, pre-auth service detection)

## File Structure (Key Directories)

```
kinhold/
├── app/
│   ├── Http/Controllers/Api/V1/   # Auth, Task, TaskList, Vault, Calendar, Chat, Family, Settings, Points, Rewards, Badges
│   ├── Http/Requests/             # Form request validation (Auth/, Task/, Vault/)
│   ├── Http/Resources/            # API response transformers
│   ├── Models/                    # Eloquent models (incl. PointTransaction, Reward, RewardPurchase, Badge)
│   ├── Services/                  # Business logic (GoogleCalendar, VaultEncryption, Agent, Points, Badge)
│   ├── Policies/                  # Authorization (Task, TaskList, VaultEntry, Family, Badge, Tag, Reward, FeaturedEvent)
│   ├── Console/Commands/          # Artisan (GenerateRecurringTasks, rewards:resolve-auctions, kinhold:tally-storage)
│   ├── Enums/                     # FamilyRole, TaskPriority, PermissionLevel, PointTransactionType, BadgeTriggerType
│   └── Mcp/                       # Laravel-native MCP server (Servers/, Tools/, Tools/Concerns/)
├── database/migrations/           # Timestamped migrations
├── resources/js/
│   ├── components/                # Vue components (layout/, common/, calendar/, tasks/, vault/, chat/, points/, badges/)
│   ├── views/                     # Page-level components (auth/, dashboard/, calendar/, tasks/, vault/, chat/, settings/, points/, badges/)
│   ├── stores/                    # Pinia stores (auth, tasks, vault, calendar, chat, points, badges)
│   ├── services/                  # API client (axios)
│   ├── composables/               # useNotification, useFamilyColors, useDarkMode
│   └── router/                    # Vue Router config
├── routes/ai.php                  # MCP route registration (/mcp endpoint)
├── docker/                        # Nginx, PHP config, entrypoint
├── docs/                          # Architecture, roadmap, conventions, this file
└── .upsun/                        # Production deployment config
```

## Dark Mode Architecture

- `darkMode: 'class'` in Tailwind config — `<html class="dark">` toggles it
- `useDarkMode` composable handles toggling + localStorage persistence
- Toggle currently lives in Settings > Appearance only — needs TopBar/mobile toggle
- CSS architecture: dark mode defaults for component classes (`.card`, `.input-base`, `.btn-*`) live in `@layer components` in `app.css`. Explicit `dark:` Tailwind utilities override these when needed. **Do NOT put dark overrides outside `@layer`** — they will beat Tailwind utilities due to CSS cascade rules.
- If dark mode appears visually broken, restart the Vite dev server first — stale Vite processes won't generate custom color utilities.

## Known Issues

- `CalendarEventResource` receives raw arrays (from Google API), not Eloquent models — may need adjustment
- Vault route conflict possible: `/vault/entry/:id` vs `/vault/:categorySlug`
- Google Calendar OAuth flow needs real credentials from Google Cloud Console
- AI Assistant needs `ANTHROPIC_API_KEY` in `.env`
- VaultEncryptionService uses simple encrypt/decrypt — verify round-trip on first real entry
- Vite dev server can go stale with high CPU — kill and restart if CSS isn't generating correctly

## Local Development Setup

**Preferred: Native (Homebrew on Mac)**
```bash
brew install php@8.3 composer postgresql@16 redis
brew services start postgresql@16
brew services start redis
createdb kinhold
cp .env.example .env
# Edit .env: DB_HOST=127.0.0.1, DB_DATABASE=kinhold, DB_USERNAME=<mac-username>, DB_PASSWORD=
composer install && npm install
php artisan key:generate
php artisan migrate && php artisan db:seed
# Tab 1: npm run dev    Tab 2: php artisan serve
# Open http://localhost:8000
```

**Alternative: Docker**
```bash
chmod +x setup.sh && ./setup.sh
```

## Deployment (Upsun)

- Repo: https://github.com/gregqualls/kinhold (Greg owns; Upsun auto-deploys from `main`)
- Project ID: `2rozcvqjtjdta` (Terra Nova org)
- Domain: kinhold.app via Cloudflare; SSL automatic
- Env vars (APP_KEY, DB, Redis, Google OAuth, Anthropic key) set on Upsun, never in repo
- PRs auto-create preview environments
- **Never use `upsun push`** — just push to GitHub
- Forks: connect own Upsun project, set own env vars, `git pull upstream main` for updates
