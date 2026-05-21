# Allergens — Implementation Plan (v1.10.0)

> Parent issue: [#312 Recipe allergen tagging and filtering](https://github.com/gregqualls/kinhold/issues/312)
> Target release: v1.10.0
> Integration branch: `feature/allergens-v1.10.0`

## Context

Recipe allergen tagging is a safety-critical feature. The driver is severe / life-threatening allergies (anaphylaxis-grade), which sets the bar for the entire design: explicit confirmation flows, conservative AI behavior, no silent suggestions of unsafe recipes, and a no-AI fallback path so the feature works for families who haven't enabled AI.

This is the first feature in Kinhold where a bug could plausibly hurt a child. Treat it as vault-grade.

## Design decisions (from QA interview, 2026-05-21)

| Decision | Choice | Why |
|---|---|---|
| Stakes | Severe / anaphylaxis | Drives strict safety defaults |
| Scope | Allergens only, not dietary preferences | Allergens are medical; preferences are softer. Preferences become a separate issue later. |
| AI policy | Auto-tag at ≥0.95 confidence; prompt user below that | Fast for obvious cases (chocolate cake = milk/eggs/wheat), human in the loop for ambiguous |
| No-AI fallback | Required everywhere | Some families don't have AI enabled. Manual entry, manual tagging, manual backfill must all work. |
| Trace handling | Two presence levels: `contains` and `may_contain`. Both filter by default. | "May contain" (shared equipment) is medically relevant for severe allergies. |
| Meal planner | Show recipe with prominent warning, require explicit confirm-to-plan | Allows separate-prep meals without hiding the risk |
| Backfill | One-time AI scan of all existing recipes after launch | Immediate coverage; reviewable via the "AI — not yet confirmed" indicator |
| Custom allergens | Yes, freely. Big 9 seeded globally; family adds their own. | Real allergies extend beyond the Big 9 (corn, cinnamon, kiwi, red dye 40, etc.) |
| Severity per member | Flat binary (has it / doesn't) | Simpler. All flagged allergens get the same warning treatment. |
| Edit permissions | Parents edit anyone; members edit their own | Matches existing family-role model |
| Provenance tracking | Yes — store `ai_auto` vs `human_confirmed`. Surface in UI but subtle, design-system-aware. | An AI-detected tag still pending review must be visually distinct from a confirmed tag |
| New member default | Empty profile + persistent "allergy profile not yet reviewed" prompt | Prevents "we forgot to set this for the new baby" failure mode |
| Allergen storage | Global Big 9 (seeded), per-family custom rows | Clean dedup, AI prompt knows the seed list |
| Module gating | Entire allergen UI hidden when family has food module disabled | Respect existing module-access model |

## Architecture

### Database

Three new tables, plus one column on `users`:

**`allergens`** — reference table
- `id` (UUID, PK)
- `family_id` (UUID, nullable FK — null = global seed)
- `name` (string, unique per family)
- `slug` (string, unique per family)
- `is_big_nine` (boolean, default false) — for badging
- `timestamps`
- Seed migration loads Big 9 (milk, eggs, fish, shellfish, tree nuts, peanuts, wheat/gluten, soy, sesame) with `family_id = null`.

**`recipe_allergens`** — pivot, recipe ↔ allergen
- `id` (UUID, PK)
- `recipe_id` (FK, cascade delete)
- `allergen_id` (FK, cascade delete)
- `presence` (enum: `contains`, `may_contain`)
- `source` (enum: `ai_auto`, `ai_suggested`, `human_confirmed`, `imported`)
- `confidence` (decimal 0-1, nullable — only set when source is AI)
- `confirmed_by` (UUID, nullable FK to users — set when human reviewed)
- `confirmed_at` (timestamp, nullable)
- `timestamps`
- Unique: (`recipe_id`, `allergen_id`, `presence`)
- Index: (`recipe_id`, `presence`)

**`member_allergens`** — pivot, user ↔ allergen
- `id` (UUID, PK)
- `user_id` (FK, cascade delete)
- `allergen_id` (FK, cascade delete)
- `timestamps`
- Unique: (`user_id`, `allergen_id`)

**`users.allergen_profile_reviewed_at`** — nullable timestamp
- Set when a parent or the member themselves explicitly confirms the profile (even if empty). Null = "not yet reviewed" → triggers persistent prompt.

### API surface

```
GET    /api/v1/allergens                              # list global + family customs
POST   /api/v1/allergens                              # add custom (parent only)
PATCH  /api/v1/allergens/{id}                         # rename custom (parent only)
DELETE /api/v1/allergens/{id}                         # remove custom (parent only)

GET    /api/v1/users/{id}/allergens                   # read member profile
PUT    /api/v1/users/{id}/allergens                   # replace member profile (parent or self)
POST   /api/v1/users/{id}/allergens/mark-reviewed     # clears the "not reviewed" prompt

GET    /api/v1/recipes/{id}/allergens                 # list (with presence + source)
PUT    /api/v1/recipes/{id}/allergens                 # replace allergen list (sets human_confirmed)
PATCH  /api/v1/recipes/{id}/allergens/{aid}           # confirm/edit a single allergen (e.g., confirm an AI suggestion)

POST   /api/v1/recipes/allergens/backfill             # kick AI backfill job (parent only)
GET    /api/v1/recipes/allergens/backfill             # job status
```

### Recipe filter extension

`RecipeService::searchRecipes()` gets a new param:
- `safe_for_members[]=member_id,member_id` — excludes recipes with any (contains | may_contain) allergen present on those members' profiles
- `safe_for=all` — uses all family members with reviewed profiles
- Returns existing filtered query unchanged when no param given

### Meal planner integration

`MealPlanService::addEntry()` accepts a new flag:
- `acknowledge_allergens: boolean` (default false)
- If the recipe has any allergen matching any active family member with a reviewed profile, the call returns 409 with a `requires_acknowledgement` payload listing affected (member, allergen, presence) tuples
- Only proceeds when client resends with `acknowledge_allergens: true`

### AI integration

Extend `RecipeImportService` prompts (`URL_SYSTEM_PROMPT`, `PHOTO_PROMPT`):
- Add an `allergens` field to the extracted JSON: `[{slug, presence, confidence}]`
- Slug must come from the seed list + the family's customs (passed into the prompt as context)
- Confidence: 0.0-1.0

In the service:
- If confidence ≥ 0.95 → write `recipe_allergens` row with `source = ai_auto`. Counts as "tagged" but UI flags as "AI — not yet confirmed" until a human edits or confirms.
- If confidence < 0.95 → write with `source = ai_suggested`. Shows in the import preview as a pre-checked-but-editable suggestion. User accepts → becomes `human_confirmed`.

Backfill job (`php artisan recipes:backfill-allergens` + queued worker):
- Iterates recipes with no `recipe_allergens` rows
- Sends ingredient list through the same AI extraction prompt
- Writes results with provenance
- Idempotent: re-running skips already-tagged recipes unless `--force` flag

### Frontend components (Vue 3, Composition API, mobile-first)

- **`AllergenBadge.vue`** — small chip showing allergen name + icon. Variants: `confirmed` (solid), `ai-unconfirmed` (dashed border or subtle outline indicator — exact treatment per design system).
- **`AllergenBadgeRow.vue`** — row of badges, used on recipe cards and detail pages.
- **`AllergenSelector.vue`** — multi-select with search + "add custom" action (parent-only).
- **`AllergyProfileEditor.vue`** — per-member screen: allergen multi-select + "mark as reviewed" CTA.
- **`AllergyProfileReviewBanner.vue`** — dashboard banner shown when any active member has `allergen_profile_reviewed_at = null`. Links to profile setup.
- **`MealPlannerAllergenWarning.vue`** — modal shown when adding a recipe that contains allergens for active members. Lists affected (member, allergen) tuples + "I understand, plan anyway" button.
- **`FamilyAllergenSettings.vue`** — admin page to manage family custom allergens.

All allergen UI is conditionally rendered based on `family.module_access.food === true`.

### Module gating

- Settings page hides "Allergens" section when food module is off
- Recipe forms hide allergen selectors
- Member profile hides allergen editor and review banner
- Filter chips hide the "safe for X" option
- Even the API endpoints return `403 module_disabled` when food access is off (defense in depth)

## Slicing — four sub-PRs into v1.10.0

All four target the integration branch `feature/allergens-v1.10.0`. Final integration PR merges that branch to `main`, bumps `config/version.php` to `1.10.0`, and `/merge` tags `v1.10.0`.

### PR 1: Foundation (schema + member profile + family settings)
- Migrations for `allergens`, `member_allergens`, `users.allergen_profile_reviewed_at`
- Seed Big 9 (global, `family_id = null`)
- `Allergen` model + `User` allergens relation
- `AllergenController` (list/add/rename/delete customs) + `UserAllergenController` (read/replace member profile + mark-reviewed)
- Policies: `AllergenPolicy`, scoped to parent-only for mutations; member-or-parent for member profile
- Vue: `AllergenSelector.vue`, `AllergyProfileEditor.vue`, `AllergyProfileReviewBanner.vue`, `FamilyAllergenSettings.vue` (settings page entry)
- Module gating: enforced on routes + UI
- Tests: API endpoints + policy + module gating + member profile review flow

### PR 2: Recipe tagging (manual)
- Migration for `recipe_allergens`
- Add `Recipe::allergens()` relation
- Extend `RecipeController` store/update to accept allergens
- New `RecipeAllergenController` for fine-grained edits
- Vue: `AllergenBadge.vue`, `AllergenBadgeRow.vue`, allergen multi-select on recipe form
- Badges render on recipe cards (`RecipesTab.vue`) and detail page
- No AI yet — all entries are `source = human_confirmed`
- Tests: recipe form save with allergens, badge rendering, edit flow

### PR 3: Filtering + meal planner integration
- `RecipeService::searchRecipes()` extension: `safe_for_members[]` param
- Recipe list UI: "Safe for member" filter chip/dropdown
- `MealPlanService::addEntry()`: returns 409 with `requires_acknowledgement` when allergen hit
- Vue: `MealPlannerAllergenWarning.vue` modal
- Module gating still enforced
- Tests: filter excludes correctly across (contains/may_contain) × (member/all-members), meal planner refuses without acknowledgement, accepts with

### PR 4: AI integration + backfill
- Extend `URL_SYSTEM_PROMPT` and `PHOTO_PROMPT` with allergen extraction (slug + presence + confidence)
- `RecipeImportService` writes allergens with `ai_auto` (≥0.95) or `ai_suggested` (<0.95)
- Import preview surfaces AI suggestions as pre-checked-but-editable
- `AllergenBadge.vue` variant for `ai-unconfirmed` (subtle visual distinction; aligned with design system)
- Confirm-AI-tag action (PATCH endpoint, single click in detail view)
- Backfill: `php artisan recipes:backfill-allergens` + queued worker + `POST /backfill` endpoint
- Tests: prompt extracts allergens, confidence routing, backfill is idempotent, AI tag → human confirm flow

### PR 5: Public recipe sharing (#311)
Bundled into v1.10.0. Targets the same integration branch. Open questions on this issue (token vs slug, attribution, OG tags) will be resolved at planning time before this PR starts. Public page should also render allergen badges (depends on #315), so this PR lands after #315 at minimum.

### Final integration PR
- Bump version: `config/version.php` → `1.10.0`
- CHANGELOG entry covering all five PRs
- ROADMAP update: add v1.10.0 entry
- Final pass on docs (this file + README if needed)
- `/merge` tags `v1.10.0` on squash

## Release strategy

- Single Upsun preview env stays attached to `feature/allergens-v1.10.0` throughout the cycle
- Each sub-PR targets the integration branch → triggers a refreshed preview env on the same branch (one env total, cost-controlled)
- Greg dogfoods each PR in the preview env before merging it into the integration branch
- Main stays clean of partial allergen work until the final integration PR

## Out of scope (becomes separate issues)

- Dietary preferences (vegetarian, kosher, halal, low-FODMAP) — separate feature, similar shape, no overlap in safety semantics
- Cross-recipe allergen analytics ("which recipes does the family eat most that contain X")
- Restaurant / takeout allergen tracking
- Push notifications for allergen warnings (initial release relies on visible badges + planner blocker)
