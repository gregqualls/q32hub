# Kinhold — Architecture & Technical Decisions

> This document records WHY we made each technical decision. Update it when decisions change.

## Decision Log

### DEC-001: Laravel 11 for Backend
**Date:** 2026-03-16
**Decision:** Use Laravel 11 as a REST API backend (no server-side rendering).
**Reasoning:** Greg is most familiar with Laravel. Laravel 11's slim application structure, built-in Sanctum auth, and Eloquent ORM make it the fastest path to a working product. The ecosystem (Forge, Vapor, Sail) gives deployment flexibility.
**Trade-off:** PHP isn't the fastest runtime, but for a family of 5 users this is irrelevant. Laravel's developer experience wins here.

### DEC-002: Vue 3 SPA (Not Livewire, Not Inertia)
**Date:** 2026-03-16
**Decision:** Full Vue 3 SPA with Composition API, communicating via REST API.
**Reasoning:** A full SPA gives the most app-like feel on mobile (critical for kids to actually use it). It also cleanly separates frontend and backend, making the API reusable for the MCP server and future mobile apps. Vue chosen over React for its lighter footprint and natural fit with the Laravel ecosystem.
**Trade-off:** More complex than Livewire/Inertia. Two build systems (PHP + Node). But the API-first approach pays dividends for MCP, future mobile app, and open-source flexibility.

### DEC-003: PostgreSQL over MySQL
**Date:** 2026-03-16
**Decision:** PostgreSQL 16 as the primary database.
**Reasoning:** Better JSON column support (vault metadata), superior full-text search (for chatbot context retrieval), and pgcrypto if we ever want database-level encryption. Upsun supports PostgreSQL well.
**Trade-off:** Slightly less Laravel tutorial coverage, but modern Laravel treats PostgreSQL as a first-class citizen.

### DEC-004: App-Level Encryption for Vault
**Date:** 2026-03-16
**Decision:** Encrypt vault `encrypted_data` field using Laravel's `encrypt()`/`decrypt()` (AES-256-CBC via APP_KEY + dedicated VAULT_ENCRYPTION_KEY).
**Reasoning:** Balances security with practicality. The chatbot can still query decrypted data server-side. Per-user encryption or zero-knowledge would prevent server-side search and break the AI chatbot feature.
**Trade-off:** A database breach WITH the encryption key would expose vault data. Mitigated by: separate VAULT_ENCRYPTION_KEY, key rotation capability, and proper server hardening.
**Future consideration:** Could add an optional per-user encryption layer for ultra-sensitive entries where chatbot access isn't needed.

### DEC-005: Hybrid Permission Model for Vault
**Date:** 2026-03-16
**Decision:** Two-tier permissions: base roles (parent=full access, child=restricted) + per-item overrides (parents can grant specific entries to specific children).
**Reasoning:** Parents need to manage everything. But a 17-year-old should be able to see their own SSN, medical info, and insurance cards. The hybrid model handles this naturally without being overly complex.
**Implementation:** `vault_permissions` table with `(vault_entry_id, user_id, permission_level)`. If no explicit permission exists for a child, they can't see it. Parents bypass permission checks entirely.

### DEC-006: Cookie-Based Auth for SPA, Token-Based for MCP
**Date:** 2026-03-16
**Decision:** Laravel Sanctum with two auth modes — SPA authentication (cookies/sessions) for the web app, and API tokens for the MCP server.
**Reasoning:** Cookie auth is more secure for browser-based SPAs (no token storage in localStorage). API tokens are necessary for the MCP server which runs as a CLI tool.
**Implementation:** Sanctum's `EnsureFrontendRequestsAreStateful` middleware handles SPA auth. MCP uses `Authorization: Bearer <token>` header. Both hit the same API endpoints.

### DEC-007: Tailwind CSS with Mobile-First Cards
**Date:** 2026-03-16
**Decision:** Tailwind CSS utility classes, designing for 375px mobile screens first.
**Reasoning:** The family will primarily access this on phones. Card-based UI with bottom navigation gives a native-app feel. Tailwind's utility approach makes responsive design straightforward and keeps the CSS maintainable.
**Design tokens:** Warm color palette (blues + ambers), rounded corners (`rounded-xl`), soft shadows, generous padding, minimum 44px touch targets.

### DEC-008: TypeScript MCP Server (Not PHP)
**Date:** 2026-03-16
**Decision:** MCP server built in TypeScript/Node.js using `@modelcontextprotocol/sdk`.
**Reasoning:** The official MCP SDK is TypeScript-first with the most mature tooling. A PHP MCP server would work but has less community support. The MCP server is a thin client that just calls the Laravel API, so the language mismatch doesn't matter.
**Trade-off:** Adds Node.js as a dependency for the MCP server. But it's a standalone tool, not part of the web app deployment.

### DEC-009: Docker Compose for Local Dev and Community Deployment
**Date:** 2026-03-16
**Decision:** Docker Compose with services for app (PHP-FPM), nginx, PostgreSQL, Redis, and Node (for building frontend). Inspired by Laravel Sail but custom-configured.
**Reasoning:** Consistent environment for Greg and any open-source contributors. One command to boot everything. Also serves as the deployment method for self-hosters.
**Services:** app (PHP 8.2 + FPM), nginx (serves SPA + proxies API), pgsql (PostgreSQL 16), redis (Redis 7), node (build step only).

### DEC-010: Upsun for Production Hosting
**Date:** 2026-03-16
**Decision:** Deploy to Upsun.com (Platform.sh) for production.
**Reasoning:** Greg's choice. Upsun handles Laravel well with managed PostgreSQL, Redis, and automatic deployments. Config lives in `.upsun/config.yaml`.
**Note:** The Docker Compose setup is independent — community self-hosters use Docker, Greg uses Upsun.

### DEC-011: Chatbot as Optional Feature (API Key Toggle)
**Date:** 2026-03-16
**Decision:** The AI chatbot is enabled/disabled based on whether `ANTHROPIC_API_KEY` is set in `.env`. No API key = feature hidden from UI.
**Reasoning:** Not everyone wants or can afford AI features. For open-source users, this should be optional. For Greg, it's a core feature.
**Implementation:** `config('kinhold.chatbot.enabled')` checks for API key presence. Frontend conditionally shows chat tab. Backend returns 503 if chatbot is called without a key.

### DEC-013: Severe-Stakes Allergen System (v1.10.0)
**Date:** 2026-05-21
**Decision:** Treat the allergen feature as safety-critical (anaphylaxis-grade), not as a soft dietary preference. Build it with conservative defaults that fail open toward "show too much" rather than "silently mark unsafe recipes safe."
**Reasoning:** Kinhold's targeted use case includes children with life-threatening allergies. The user briefing explicitly called out that this is the first feature in the app where a bug could plausibly hurt a child. The whole feature design follows from that constraint.
**Key invariants enforced by the code:**
- Members with `allergen_profile_reviewed_at = null` are NEVER used by the `safe_for_members` filter or the meal-planner safety blocker. False-safe filtering is treated as worse than no filtering. Tests in `AllergenFilteringTest` lock this.
- `acknowledge_allergens` is a request-time flag, never a profile-level setting. Bypassing the planner guard requires an explicit per-action acknowledgement and the API returns 409 + structured `hits[]` otherwise.
- AI-tagged allergens land with `source = ai_auto` or `ai_suggested`. The recipe detail view renders them as visually distinct "unconfirmed" badges (dashed outline + reduced opacity) until a parent one-click confirms. The planner safety blocker treats them as real allergens regardless.
- Mass-assignment hardening: `allergen_profile_reviewed_at`, `share_token`, and `share_visible_attribution` are NOT in `$fillable`. Only controllers that own the contract use `forceFill`. Prevents a future `User::update($request->all())` from silently marking a profile reviewed.
- Public share URLs use 22-char base62 tokens (~131 bits of entropy). Revoke clears the token; re-sharing later mints a brand-new token by design (old links die permanently on revoke).
- Backfill is parent-only, throttled at 2/day per family at the route layer, and rate-limited at 30 Anthropic calls/min per family inside the queue worker. The job takes both `recipeId` and `familyId` and asserts they match — a misconfigured dispatcher can't cross-tag.
**Allergen storage shape:** Global Big 9 rows share `family_id = null`; family-scoped custom allergens carry the family's id. `availableToFamily()` scope returns the union. Cleanly deduplicates the Big 9 across the install and keeps "custom" semantics in one place.
**Multi-image:** `recipes.image_path` stays as a denormalized cache of the primary image so card readers don't need a relation load; the source of truth for the gallery is `recipe_images` synced through `RecipeService::syncImages()`. Backwards-compat is structural, not a special case.
**Public sharing renders via Blade SSR** (not Vue): the share page needs proper OG/Twitter Card meta tags for social unfurl, must be SEO-friendly and fast, and doesn't need interactivity. PWA `navigateFallbackDenylist` was updated to include `/^\/r\//` so the service worker passes share URLs straight through to the server.
**MCP coverage:** 13 new actions in `kinhold-food` cover the entire surface (allergens, member profiles, recipe-allergen single-row edits, backfill, sharing). `recipe_list` accepts `safe_for_members[]` + `safe_for`; `meal_plan_add_entry` accepts `acknowledge_allergens` and surfaces the structured hit list on block. Matches the `api_coverage_required` rule in `.dotclaude.project.yaml`.
**Why a dedicated Blade view instead of the SPA's recipe detail:** the public page is a different design surface (no nav, no auth, must look beautiful to non-users, must be print-friendly). Trying to reuse the SPA's detail view would have meant adding "public mode" branches throughout the SPA. A focused Blade template is smaller, faster, and easier to design without app chrome.

### DEC-012: Soft Single-Family Enforcement on Self-Hosted ([#138](https://github.com/gregqualls/kinhold/issues/138))
**Date:** 2026-04-30
**Decision:** Enforce the LICENSE's single-family limit via a sticky SPA banner + a `Log::warning` line on Nth-family creation. Do **not** hard-stop family creation. An internal env flag (`COMMERCIAL_LICENSE_ACKNOWLEDGED=true`) suppresses the banner — handed out privately to commercial licensees, never advertised in `.env.example`, the SPA, or self-hosting docs.
**Reasoning:** Three constraints pulled toward this shape: (1) the existing Google OAuth flow creates a new family for every first-time login — a hard-stop would brick legitimate spousal signups via OAuth; (2) Kinhold's positioning targets the privacy-first / self-host audience, who would reject any phone-home license check; (3) the LICENSE addendum is the actual legal teeth, so code only needs to ensure operators are *informed* of the limit, not technically prevented from violating it. Standard OSS enforcement model — same shape as GitLab EE, Sentry's BSL, Elastic itself: code is a speed bump for informed consent, the LICENSE is the contract.
**Why the bypass flag is undocumented in operator-facing surfaces:** if every self-hoster sees "set this env var to clear the warning" they will, and the warning becomes meaningless. Keeping the mechanism internal means the *only* path for an operator with multiple families to clear the banner is to actually contact us — at which point we can either issue a commercial license or point them at the hosted version. This is also why the banner copy points at a GitHub issue template ("Contact us") rather than naming the env var.
**Implementation:** `app/Services/LicenseEnforcementService.php` exposes `shouldWarn()` (true when self-hosted + family count > 1 + not acknowledged). The `/api/v1/config` response includes a `license` block consumed by `LicenseWarningBanner.vue`. `Family::booted()` registers a `created` listener that emits a forensic log line on each Nth family creation in self-hosted mode regardless of acknowledgement.
**Why not hard-stop:** documented above and in the [LICENSE addendum](../LICENSE). The OAuth-without-invite-code gap (now in the ROADMAP parking lot) would need to be fixed first before hard-stop becomes viable; revisit if the project ever takes on commercial licensing infrastructure.

## Data Flow Patterns

### SPA → API → Database
```
Vue Component → Pinia Store Action → Axios (api.js) → Laravel Route → Controller → Service → Model → PostgreSQL
                                                                                    ↓
Vue Component ← Pinia Store State ← Axios Response ← API Resource ← Controller ← Service
```

### MCP → API → Database
```
Claude → MCP Server (stdio) → HTTP Request → Laravel Route → Controller → Service → Model → PostgreSQL
                                                                                      ↓
Claude ← MCP Server (stdout) ← HTTP Response ← API Resource ← Controller ← Service
```

### Chatbot Flow
```
User sends message → ChatController → ChatbotService
  → Gathers context (calendar events, tasks, vault entries user can access)
  → Sends to Anthropic API with system prompt + context + user message
  → Returns AI response
  → Stores in chat history
```

## Security Model

1. **Transport:** HTTPS in production (enforced in AppServiceProvider). HTTP acceptable for local dev only.
2. **Authentication:** Sanctum with CSRF protection for SPA. API tokens for machine clients.
3. **Authorization:** Policies on every model. Parent role has broad access. Child role requires explicit vault permissions.
4. **Encryption at rest:** Vault `encrypted_data` field uses Laravel encryption. Calendar tokens encrypted. Document files can be optionally encrypted.
5. **Input validation:** Form Request classes validate all input before it reaches controllers.
6. **SQL injection:** Eloquent ORM parameterizes all queries.
7. **XSS:** Vue 3 auto-escapes template output. API returns JSON, not HTML.
8. **CORS:** Configured to allow only the SPA origin.
9. **Rate limiting:** Laravel's built-in throttle middleware on auth and API routes.

## Scaling Considerations (Future)

These don't matter for a family of 5, but matter for open-source adoption:
- PostgreSQL can handle thousands of families on a single instance
- Redis caching on frequently-read data (calendar events, vault categories)
- Queue workers for Google Calendar sync (don't block HTTP requests)
- File storage abstraction (local disk → S3 → any Flysystem adapter)
- Horizontal scaling: stateless API behind a load balancer (sessions in Redis)
