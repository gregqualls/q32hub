<?php

namespace App\Mcp\Tools;

use App\Enums\AllergenSource;
use App\Enums\MealSlot;
use App\Exceptions\AllergenAcknowledgementRequired;
use App\Mcp\Tools\Concerns\MergesUpdates;
use App\Mcp\Tools\Concerns\RequiresModule;
use App\Mcp\Tools\Concerns\ScopesToFamily;
use App\Models\Allergen;
use App\Models\FamilyRestaurant;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\MealPreset;
use App\Models\ProductCatalog;
use App\Models\Rating;
use App\Models\Recipe;
use App\Models\RecipeAllergen;
use App\Models\Restaurant;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\Tag;
use App\Models\User;
use App\Policies\UserAllergenPolicy;
use App\Services\MealPlanService;
use App\Services\RecipeImportService;
use App\Services\RecipeService;
use App\Services\RestaurantImportService;
use App\Services\ShoppingListService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('kinhold-food')]
#[Description(<<<'DESC'
Recipes, shopping lists, meal plans, restaurants, and meal presets — the family kitchen.

Recipes:
  recipe_list (search?, tag?, favorite?, sort?, per_page?, safe_for_members?, safe_for?) — Family recipes (paginated). `safe_for_members` is an array of family-member IDs; recipes containing any of their allergens are excluded. `safe_for: "all"` filters against every reviewed-profile family member. Members without a reviewed profile are silently skipped (no false-safe filtering).
  recipe_show (recipe_id*) — Full recipe with ingredients, cook logs, ratings.
  recipe_create (title*, [recipe fields]) — Subject to recipe_creation policy.
  recipe_update (recipe_id*, [any field]).
  recipe_delete (recipe_id*) — Soft-deletes.
  recipe_restore (recipe_id*) — Restore soft-deleted recipe.
  recipe_toggle_favorite (recipe_id*).
  recipe_add_cook_log (recipe_id*, cooked_at*, servings_made?, notes?).
  recipe_cook_logs (recipe_id*) — History.
  recipe_rate (recipe_id*, score*) — 1-5.
  recipe_ratings (recipe_id*).
  recipe_import_url (url*, preview?) — Scrape recipe from URL. preview=true returns extracted data without saving.

Recipe create/update accept `allergens: [{allergen_id, presence}]` and `images: [{id?, path, sort_order?, is_primary?}]` arrays for tagging and multi-image management. See allergen actions below for the allergen reference data and the per-member profile.

Recipe sharing (parent-only):
  recipe_share (recipe_id*, visible_attribution?) — Mint a public token. Idempotent: re-calling returns the same URL.
  recipe_share_update (recipe_id*, visible_attribution*) — Toggle attribution without rotating the token.
  recipe_unshare (recipe_id*) — Revoke. Old URL hard-404s; re-sharing later mints a new token.

Recipe-allergen edits (parent-only):
  recipe_allergen_add (recipe_id*, allergen_id*, presence*) — Add a single (contains|may_contain) row, idempotent on duplicate.
  recipe_allergen_patch (recipe_id*, row_id*, presence?, action?) — Confirm an AI tag (default), change presence, or remove (action: "remove").
  recipe_allergen_backfill (force?) — Queue AI allergen extraction across the family's recipes. Idempotent unless force=true. Throttled to 2/day per family.

Allergens (Big 9 are global, custom rows are family-scoped):
  allergen_list — All allergens available to the family.
  allergen_create (name*) — Add a custom allergen (parent-only).
  allergen_update (allergen_id*, name*) — Rename a custom (parent-only). Big 9 immutable.
  allergen_delete (allergen_id*) — Remove a custom (parent-only). Big 9 immutable.

Member allergy profiles:
  allergen_profile_show (user_id*) — Read a member's profile (any family member).
  allergen_profile_set (user_id*, allergen_ids*) — Replace a member's allergens. Parents may edit anyone; members only themselves.
  allergen_profile_mark_reviewed (user_id*) — Clear the dashboard "not yet reviewed" prompt without changing the list.

Meal plan add_entry accepts `acknowledge_allergens: true` to bypass the safety blocker when a recipe contains an allergen for a reviewed family member. Without the flag, returns `{requires_acknowledgement: true, hits: [...]}` listing the affected (member, allergen, presence) tuples.

Shopping lists & items:
  shopping_list_lists — All family shopping lists.
  shopping_create_list (name*, store_name?).
  shopping_show_list (list_id*) — List with sorted items.
  shopping_update_list (list_id*, name?, store_name?).
  shopping_delete_list (list_id*).
  shopping_complete_trip (list_id*) — Mark a shopping trip as completed.
  shopping_add_item (list_id*, name*, quantity?, category?, notes?, is_recurring?, default_quantity?).
  shopping_update_item (item_id*, [any field — name, quantity, category, notes, is_recurring, sort_order]).
  shopping_remove_item (item_id*).
  shopping_check_item (item_id*) / shopping_uncheck_item (item_id*).
  shopping_mark_on_hand (item_id*) / shopping_clear_on_hand (item_id*).
  shopping_move_item (item_id*, target_list_id*).
  shopping_toggle_recurring (item_id*).
  shopping_clear_checked (list_id*) — Removes checked items, resets recurring.
  shopping_add_recipe (list_id*, recipe_id*, ingredient_ids?) — Bulk-add (selective).
  shopping_search_catalog (q*) — Autocomplete from ProductCatalog + family history.

Meal plans:
  meal_plan_list (week_start?) — Plans, optionally for a week.
  meal_plan_current — Get-or-create the current week's plan.
  meal_plan_create (week_start*).
  meal_plan_show (plan_id*).
  meal_plan_add_entry (plan_id*, date*, meal_slot*, ONE OF: recipe_id|restaurant_id|meal_preset_id|custom_title, notes?, servings?, assigned_cooks?, sort_order?).
    Each entry has exactly ONE source — pick recipe_id (a saved recipe), restaurant_id (eat out / takeout), meal_preset_id (a reusable label like "Leftovers" / "Fend for Yourself"), or custom_title (a free-form plain note like "Sandwiches"). `notes` is supplemental detail, NOT the entry's title.
  meal_plan_update_entry (entry_id*, [any field]).
    To switch an entry's TYPE (e.g. from preset "Leftovers" to plain note "Sandwiches"): set the new source field AND explicitly clear the old one — `meal_preset_id: null` (or `""`) to drop a preset, same for recipe_id / restaurant_id. Just setting custom_title without clearing the preset will leave the preset taking visual precedence.
  meal_plan_remove_entry (entry_id*).
  meal_plan_move_entry (entry_id*, date*, meal_slot*).
  meal_plan_preview_shopping (plan_id*, days?, start?, shopping_list_id?) — Preview ingredients to add.
  meal_plan_add_to_shopping (plan_id*, selections*, shopping_list_id?) — Add curated ingredients.
  meal_plan_generate_shopping (plan_id*) — Force full regeneration.

Meal presets (parent-only writes):
  meal_preset_list.
  meal_preset_create (label*, icon?, sort_order?).
  meal_preset_update (preset_id*, label?, icon?, sort_order?).
  meal_preset_delete (preset_id*) — Non-system only.

Restaurants:
  restaurant_list (tag?) — Family-linked restaurants.
  restaurant_show (restaurant_id*).
  restaurant_create (name*, address?, phone?, google_maps_url?, menu_url?, image_url?, tag_ids?).
  restaurant_update (restaurant_id*, [any field; notes/is_favorite are family-pivot fields]).
  restaurant_delete (restaurant_id*) — Unlinks from family.
  restaurant_import (url*, preview?) — Scrape from URL.
  restaurant_rate (restaurant_id*, score*) — 1-5.

Meal slots: breakfast, lunch, dinner, snack.
Photo uploads (recipes/restaurants) are not supported via MCP — use the API directly with multipart uploads.
DESC)]
class KinholdFood extends Tool
{
    use MergesUpdates, RequiresModule, ScopesToFamily;

    public const MODULE = 'food';

    public function schema($schema): array
    {
        return [
            'action' => $schema->string()->required()->enum([
                // Recipes
                'recipe_list', 'recipe_show', 'recipe_create', 'recipe_update', 'recipe_delete',
                'recipe_restore', 'recipe_toggle_favorite',
                'recipe_add_cook_log', 'recipe_cook_logs', 'recipe_rate', 'recipe_ratings',
                'recipe_import_url',
                // Recipe sharing
                'recipe_share', 'recipe_share_update', 'recipe_unshare',
                // Recipe-allergen single-row + backfill
                'recipe_allergen_add', 'recipe_allergen_patch', 'recipe_allergen_backfill',
                // Allergens (family-scoped reference data)
                'allergen_list', 'allergen_create', 'allergen_update', 'allergen_delete',
                // Member allergy profiles
                'allergen_profile_show', 'allergen_profile_set', 'allergen_profile_mark_reviewed',
                // Shopping
                'shopping_list_lists', 'shopping_create_list', 'shopping_show_list',
                'shopping_update_list', 'shopping_delete_list', 'shopping_complete_trip',
                'shopping_add_item', 'shopping_update_item', 'shopping_remove_item',
                'shopping_check_item', 'shopping_uncheck_item',
                'shopping_mark_on_hand', 'shopping_clear_on_hand',
                'shopping_move_item', 'shopping_toggle_recurring',
                'shopping_clear_checked', 'shopping_add_recipe', 'shopping_search_catalog',
                // Meal plans
                'meal_plan_list', 'meal_plan_current', 'meal_plan_create', 'meal_plan_show',
                'meal_plan_add_entry', 'meal_plan_update_entry', 'meal_plan_remove_entry', 'meal_plan_move_entry',
                'meal_plan_preview_shopping', 'meal_plan_add_to_shopping', 'meal_plan_generate_shopping',
                // Meal presets
                'meal_preset_list', 'meal_preset_create', 'meal_preset_update', 'meal_preset_delete',
                // Restaurants
                'restaurant_list', 'restaurant_show', 'restaurant_create', 'restaurant_update',
                'restaurant_delete', 'restaurant_import', 'restaurant_rate',
            ])->description('Action to perform'),

            // IDs
            'recipe_id' => $schema->string()->description('Recipe UUID — FK on meal_plan_add/update_entry; entry source. Pass null/"" on update to clear.'),
            'list_id' => $schema->string()->description('Shopping list UUID'),
            'item_id' => $schema->string()->description('Shopping item UUID'),
            'plan_id' => $schema->string()->description('Meal plan UUID'),
            'entry_id' => $schema->string()->description('Meal plan entry UUID'),
            'preset_id' => $schema->string()->description('Meal preset UUID — used by meal_preset_update/meal_preset_delete (operates on the preset itself)'),
            'meal_preset_id' => $schema->string()->description('Meal preset UUID — FK on meal_plan_add/update_entry; entry source. Pass null/"" on update to clear.'),
            'custom_title' => $schema->string()->description('Free-form entry title (meal_plan_add/update_entry) — used when an entry is a plain note like "Sandwiches" instead of a recipe/restaurant/preset. Pass null/"" on update to clear.'),
            'restaurant_id' => $schema->string()->description('Restaurant UUID — FK on meal_plan_add/update_entry; entry source. Pass null/"" on update to clear.'),
            'target_list_id' => $schema->string()->description('Target shopping list UUID (shopping_move_item)'),

            // Generic params
            'title' => $schema->string()->description('Title (recipes)'),
            'name' => $schema->string()->description('Name (shopping items, restaurants, lists, presets)'),
            'description' => $schema->string()->description('Description'),
            'notes' => $schema->string()->description('Notes (varies by action)'),
            'url' => $schema->string()->description('URL for imports (recipe_import_url, restaurant_import)'),
            'preview' => $schema->boolean()->description('Preview-only: extract data without saving'),

            // Recipe-specific
            'search' => $schema->string()->description('Search keyword for recipe_list'),
            'tag' => $schema->string()->description('Tag UUID filter (recipe_list, restaurant_list)'),
            'favorite' => $schema->boolean()->description('Filter recipe_list to favorites only'),
            'sort' => $schema->string()->description('Sort key for recipe_list (e.g. title, created_at, rating)'),
            'per_page' => $schema->integer()->description('Page size for recipe_list'),
            'cooked_at' => $schema->string()->description('Cook log timestamp (recipe_add_cook_log)'),
            'servings_made' => $schema->integer()->description('Servings made (recipe_add_cook_log)'),
            'score' => $schema->integer()->description('Rating 1-5 (recipe_rate, restaurant_rate)'),
            'ingredients' => $schema->array()->description('Recipe ingredients (recipe_create/update). Array of {name, quantity, unit, preparation, is_optional}.'),
            'instructions' => $schema->string()->description('Recipe instructions (markdown)'),
            'prep_time_minutes' => $schema->integer()->description('Prep time'),
            'cook_time_minutes' => $schema->integer()->description('Cook time'),
            'servings' => $schema->integer()->description('Default servings'),
            'image_path' => $schema->string()->description('Recipe image path (already-stored)'),
            'tag_ids' => $schema->array()->items($schema->string())->description('Tag UUIDs to attach'),

            // Shopping-specific
            'store_name' => $schema->string()->description('Store name (shopping list)'),
            'quantity' => $schema->string()->description('Item quantity (shopping_add_item)'),
            'category' => $schema->string()->description('Item category'),
            'is_recurring' => $schema->boolean()->description('Mark item as recurring'),
            'default_quantity' => $schema->string()->description('Default quantity restored when a recurring shopping item resets (shopping_add_item)'),
            'q' => $schema->string()->description('Search query (shopping_search_catalog)'),
            'ingredient_ids' => $schema->array()->items($schema->string())->description('Recipe ingredient UUIDs to add (shopping_add_recipe)'),

            // Meal plan / preset
            'week_start' => $schema->string()->description('Week start date YYYY-MM-DD'),
            'date' => $schema->string()->description('Entry date YYYY-MM-DD'),
            'meal_slot' => $schema->string()->enum(['breakfast', 'lunch', 'dinner', 'snack'])->description('Meal slot'),
            'days' => $schema->integer()->description('Number of days for shopping preview (default 7)'),
            'start' => $schema->string()->description('Start date for shopping preview YYYY-MM-DD'),
            'shopping_list_id' => $schema->string()->description('Target shopping list UUID'),
            'selections' => $schema->array()->description('Curated entry selections for meal_plan_add_to_shopping. Array of {entry_id, ingredient_ids?}.'),
            'label' => $schema->string()->description('Meal preset label'),
            'icon' => $schema->string()->description('Icon (presets, restaurants)'),
            'sort_order' => $schema->integer()->description('Sort order (presets, recipes, meal-plan entries, shopping items)'),
            'assigned_cooks' => $schema->array()->items($schema->string())->description('User UUIDs assigned as cooks for a meal-plan entry'),

            // Restaurant
            'address' => $schema->string()->description('Restaurant address'),
            'phone' => $schema->string()->description('Restaurant phone'),
            'google_maps_url' => $schema->string()->description('Google Maps URL'),
            'menu_url' => $schema->string()->description('Menu URL'),
            'image_url' => $schema->string()->description('Image URL (already-stored)'),
            'is_favorite' => $schema->boolean()->description('Restaurant pivot: family favorite'),
        ];
    }

    public function handle(Request $request): Response
    {
        return match ($request->get('action')) {
            // Recipes
            'recipe_list' => $this->recipeList($request),
            'recipe_show' => $this->recipeShow($request),
            'recipe_create' => $this->recipeCreate($request),
            'recipe_update' => $this->recipeUpdate($request),
            'recipe_delete' => $this->recipeDelete($request),
            'recipe_restore' => $this->recipeRestore($request),
            'recipe_toggle_favorite' => $this->recipeToggleFavorite($request),
            'recipe_add_cook_log' => $this->recipeAddCookLog($request),
            'recipe_cook_logs' => $this->recipeCookLogs($request),
            'recipe_rate' => $this->recipeRate($request),
            'recipe_ratings' => $this->recipeRatings($request),
            'recipe_import_url' => $this->recipeImportUrl($request),

            // Shopping
            'shopping_list_lists' => $this->shoppingListLists(),
            'shopping_create_list' => $this->shoppingCreateList($request),
            'shopping_show_list' => $this->shoppingShowList($request),
            'shopping_update_list' => $this->shoppingUpdateList($request),
            'shopping_delete_list' => $this->shoppingDeleteList($request),
            'shopping_complete_trip' => $this->shoppingCompleteTrip($request),
            'shopping_add_item' => $this->shoppingAddItem($request),
            'shopping_update_item' => $this->shoppingUpdateItem($request),
            'shopping_remove_item' => $this->shoppingRemoveItem($request),
            'shopping_check_item' => $this->shoppingCheckItem($request),
            'shopping_uncheck_item' => $this->shoppingUncheckItem($request),
            'shopping_mark_on_hand' => $this->shoppingMarkOnHand($request),
            'shopping_clear_on_hand' => $this->shoppingClearOnHand($request),
            'shopping_move_item' => $this->shoppingMoveItem($request),
            'shopping_toggle_recurring' => $this->shoppingToggleRecurring($request),
            'shopping_clear_checked' => $this->shoppingClearChecked($request),
            'shopping_add_recipe' => $this->shoppingAddRecipe($request),
            'shopping_search_catalog' => $this->shoppingSearchCatalog($request),

            // Meal plans
            'meal_plan_list' => $this->mealPlanList($request),
            'meal_plan_current' => $this->mealPlanCurrent(),
            'meal_plan_create' => $this->mealPlanCreate($request),
            'meal_plan_show' => $this->mealPlanShow($request),
            'meal_plan_add_entry' => $this->mealPlanAddEntry($request),
            'meal_plan_update_entry' => $this->mealPlanUpdateEntry($request),
            'meal_plan_remove_entry' => $this->mealPlanRemoveEntry($request),
            'meal_plan_move_entry' => $this->mealPlanMoveEntry($request),
            'meal_plan_preview_shopping' => $this->mealPlanPreviewShopping($request),
            'meal_plan_add_to_shopping' => $this->mealPlanAddToShopping($request),
            'meal_plan_generate_shopping' => $this->mealPlanGenerateShopping($request),

            // Meal presets
            'meal_preset_list' => $this->mealPresetList(),
            'meal_preset_create' => $this->mealPresetCreate($request),
            'meal_preset_update' => $this->mealPresetUpdate($request),
            'meal_preset_delete' => $this->mealPresetDelete($request),

            // Restaurants
            'restaurant_list' => $this->restaurantList($request),
            'restaurant_show' => $this->restaurantShow($request),
            'restaurant_create' => $this->restaurantCreate($request),
            'restaurant_update' => $this->restaurantUpdate($request),
            'restaurant_delete' => $this->restaurantDelete($request),
            'restaurant_import' => $this->restaurantImport($request),
            'restaurant_rate' => $this->restaurantRate($request),

            // Allergens
            'allergen_list' => $this->allergenList(),
            'allergen_create' => $this->allergenCreate($request),
            'allergen_update' => $this->allergenUpdate($request),
            'allergen_delete' => $this->allergenDelete($request),

            // Member allergy profile
            'allergen_profile_show' => $this->allergenProfileShow($request),
            'allergen_profile_set' => $this->allergenProfileSet($request),
            'allergen_profile_mark_reviewed' => $this->allergenProfileMarkReviewed($request),

            // Recipe-allergen single-row + backfill
            'recipe_allergen_add' => $this->recipeAllergenAdd($request),
            'recipe_allergen_patch' => $this->recipeAllergenPatch($request),
            'recipe_allergen_backfill' => $this->recipeAllergenBackfill($request),

            // Recipe sharing
            'recipe_share' => $this->recipeShare($request),
            'recipe_share_update' => $this->recipeShareUpdate($request),
            'recipe_unshare' => $this->recipeUnshare($request),

            default => Response::error("Unknown action: {$request->get('action')}"),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recipes
    // ─────────────────────────────────────────────────────────────────────────

    private function recipeList(Request $request): Response
    {
        $service = app(RecipeService::class);
        $filters = array_filter([
            'search' => $request->get('search'),
            'tag' => $request->get('tag'),
            'favorite' => $request->get('favorite'),
            'sort' => $request->get('sort'),
            'per_page' => $request->get('per_page'),
            'safe_for' => $request->get('safe_for'),
            'safe_for_members' => $request->get('safe_for_members'),
        ], fn ($v) => $v !== null);

        $recipes = $service->searchRecipes($this->family(), $filters);

        return Response::json([
            'recipes' => collect($recipes->items())->map(fn (Recipe $r) => $this->summarizeRecipe($r))->toArray(),
            'pagination' => [
                'total' => $recipes->total(),
                'per_page' => $recipes->perPage(),
                'current_page' => $recipes->currentPage(),
                'last_page' => $recipes->lastPage(),
            ],
        ]);
    }

    private function recipeShow(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('view', $recipe)) {
            return $denied;
        }

        $recipe->load(['ingredients', 'cookLogs.user', 'ratings.user', 'tags', 'creator']);

        return Response::json([
            'recipe' => array_merge($this->summarizeRecipe($recipe), [
                'instructions' => $recipe->instructions,
                'ingredients' => $recipe->ingredients->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity,
                    'unit' => $i->unit, 'preparation' => $i->preparation, 'is_optional' => (bool) $i->is_optional,
                ])->toArray(),
                'cook_logs' => $recipe->cookLogs->map(fn ($l) => [
                    'id' => $l->id, 'cooked_at' => $l->cooked_at?->toIso8601String(),
                    'user' => $l->user?->name, 'servings_made' => $l->servings_made, 'notes' => $l->notes,
                ])->toArray(),
                'ratings' => $recipe->ratings->map(fn ($r) => [
                    'id' => $r->id, 'user' => $r->user?->name, 'score' => $r->score,
                ])->toArray(),
                'creator' => $recipe->creator?->name,
            ]),
        ]);
    }

    private function recipeCreate(Request $request): Response
    {
        if ($denied = $this->authorize('create', Recipe::class)) {
            return $denied;
        }

        $title = $request->get('title');
        if (! $title) {
            return Response::error('title is required for recipe_create.');
        }

        $service = app(RecipeService::class);
        $data = array_filter([
            'title' => $title,
            'description' => $request->get('description'),
            'instructions' => $request->get('instructions'),
            'ingredients' => $request->get('ingredients'),
            'prep_time_minutes' => $request->get('prep_time_minutes'),
            'cook_time_minutes' => $request->get('cook_time_minutes'),
            'servings' => $request->get('servings'),
            'image_path' => $request->get('image_path'),
            'images' => $request->get('images'),
            'tag_ids' => $request->get('tag_ids'),
            'allergens' => $request->get('allergens'),
        ], fn ($v) => $v !== null);

        $recipe = $service->createRecipe($this->family(), $this->user(), $data);

        return Response::json([
            'message' => "Recipe \"{$recipe->title}\" created.",
            'recipe' => $this->summarizeRecipe($recipe),
        ]);
    }

    private function recipeUpdate(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }

        $data = $this->mergeUpdates(
            $request,
            simpleFields: ['title', 'ingredients', 'tag_ids', 'allergens', 'images'],
            nullableFields: [
                'description', 'instructions', 'prep_time_minutes',
                'cook_time_minutes', 'servings', 'image_path',
            ],
        );

        $service = app(RecipeService::class);
        $recipe = $service->updateRecipe($recipe, $data, $this->user());

        return Response::json([
            'message' => "Recipe \"{$recipe->title}\" updated.",
            'recipe' => $this->summarizeRecipe($recipe),
        ]);
    }

    private function recipeDelete(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('delete', $recipe)) {
            return $denied;
        }

        app(RecipeService::class)->deleteRecipe($recipe);

        return Response::text("Recipe \"{$recipe->title}\" deleted.");
    }

    private function recipeRestore(Request $request): Response
    {
        $recipeId = $request->get('recipe_id');
        if (! $recipeId) {
            return Response::error('recipe_id is required.');
        }

        $recipe = Recipe::withTrashed()
            ->where('family_id', $this->familyId())
            ->find($recipeId);

        if (! $recipe) {
            return Response::error("Recipe not found: {$recipeId}");
        }

        if ($denied = $this->authorize('restore', $recipe)) {
            return $denied;
        }

        app(RecipeService::class)->restoreRecipe($recipe);
        $recipe->load(['ingredients', 'tags', 'creator']);

        return Response::json([
            'message' => "Recipe \"{$recipe->title}\" restored.",
            'recipe' => $this->summarizeRecipe($recipe),
        ]);
    }

    private function recipeToggleFavorite(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }

        $recipe = app(RecipeService::class)->toggleFavorite($recipe);

        return Response::json([
            'message' => $recipe->is_favorite ? 'Marked as favorite.' : 'Removed from favorites.',
            'recipe' => $this->summarizeRecipe($recipe),
        ]);
    }

    private function recipeAddCookLog(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('addCookLog', $recipe)) {
            return $denied;
        }

        $cookedAt = $request->get('cooked_at');
        if (! $cookedAt) {
            return Response::error('cooked_at is required for recipe_add_cook_log.');
        }

        $log = app(RecipeService::class)->addCookLog($recipe, $this->user(), [
            'cooked_at' => $cookedAt,
            'servings_made' => $request->get('servings_made'),
            'notes' => $request->get('notes'),
        ]);

        return Response::json([
            'message' => "Cook log added for \"{$recipe->title}\".",
            'log' => [
                'id' => $log->id,
                'cooked_at' => $log->cooked_at?->toIso8601String(),
                'servings_made' => $log->servings_made,
                'notes' => $log->notes,
            ],
        ]);
    }

    private function recipeCookLogs(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('view', $recipe)) {
            return $denied;
        }

        $logs = $recipe->cookLogs()->with('user')->orderByDesc('cooked_at')->limit(50)->get();

        return Response::json([
            'cook_logs' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'user' => $l->user?->name,
                'cooked_at' => $l->cooked_at?->toIso8601String(),
                'servings_made' => $l->servings_made,
                'notes' => $l->notes,
            ])->toArray(),
        ]);
    }

    private function recipeRate(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('rate', $recipe)) {
            return $denied;
        }

        $score = $request->get('score');
        if (! $score || $score < 1 || $score > 5) {
            return Response::error('score must be an integer 1-5.');
        }

        $rating = app(RecipeService::class)->rateRecipe($recipe, $this->user(), (int) $score);

        return Response::json([
            'message' => "Rated \"{$recipe->title}\" {$score}/5.",
            'rating' => ['id' => $rating->id, 'score' => $rating->score],
        ]);
    }

    private function recipeRatings(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }

        if ($denied = $this->authorize('view', $recipe)) {
            return $denied;
        }

        $ratings = $recipe->ratings()->with('user')->get();

        return Response::json([
            'ratings' => $ratings->map(fn ($r) => [
                'id' => $r->id,
                'user' => $r->user?->name,
                'score' => $r->score,
            ])->toArray(),
        ]);
    }

    private function recipeImportUrl(Request $request): Response
    {
        $url = $request->get('url');
        if (! $url) {
            return Response::error('url is required for recipe_import_url.');
        }

        $service = app(RecipeImportService::class);
        $preview = (bool) $request->get('preview', false);

        try {
            $result = $service->importFromUrl($url, $this->family(), $this->user(), $preview);
        } catch (\Exception $e) {
            return Response::error('Import failed: '.$e->getMessage());
        }

        if ($preview) {
            return Response::json(['preview' => $result]);
        }

        return Response::json([
            'message' => 'Recipe imported from URL.',
            'recipe' => $this->summarizeRecipe($result['recipe']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shopping
    // ─────────────────────────────────────────────────────────────────────────

    private function shoppingListLists(): Response
    {
        $lists = ShoppingList::where('family_id', $this->familyId())
            ->orderByDesc('updated_at')
            ->withCount('items')
            ->get();

        return Response::json([
            'lists' => $lists->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'store_name' => $l->store_name,
                'item_count' => $l->items_count,
                'updated_at' => $l->updated_at->toIso8601String(),
            ])->toArray(),
        ]);
    }

    private function shoppingCreateList(Request $request): Response
    {
        if ($denied = $this->authorize('create', ShoppingList::class)) {
            return $denied;
        }

        $name = $request->get('name');
        if (! $name) {
            return Response::error('name is required for shopping_create_list.');
        }

        $list = app(ShoppingListService::class)->createList(
            $this->family(),
            $this->user(),
            $name,
            $request->get('store_name'),
        );

        return Response::json([
            'message' => "Shopping list \"{$list->name}\" created.",
            'list' => ['id' => $list->id, 'name' => $list->name, 'store_name' => $list->store_name],
        ]);
    }

    private function shoppingShowList(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('view', $list)) {
            return $denied;
        }

        $list->load(['items' => fn ($q) => $q->orderBy('category')->orderBy('sort_order')->orderBy('name'), 'creator']);

        return Response::json([
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'store_name' => $list->store_name,
                'creator' => $list->creator?->name,
                'items' => $list->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => $i->quantity,
                    'category' => $i->category,
                    'is_checked' => (bool) $i->checked_at,
                    'is_on_hand' => (bool) $i->on_hand_at,
                    'is_recurring' => (bool) $i->is_recurring,
                    'notes' => $i->notes,
                ])->toArray(),
            ],
        ]);
    }

    private function shoppingUpdateList(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('update', $list)) {
            return $denied;
        }

        $updates = array_filter([
            'name' => $request->get('name'),
            'store_name' => $request->get('store_name'),
        ], fn ($v) => $v !== null);

        $list->update($updates);

        return Response::json([
            'message' => "Shopping list \"{$list->name}\" updated.",
            'list' => ['id' => $list->id, 'name' => $list->name],
        ]);
    }

    private function shoppingDeleteList(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('delete', $list)) {
            return $denied;
        }

        $name = $list->name;
        $list->delete();

        return Response::text("Shopping list \"{$name}\" deleted.");
    }

    private function shoppingCompleteTrip(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('completeTrip', $list)) {
            return $denied;
        }

        app(ShoppingListService::class)->completeTrip($list);

        return Response::text("Shopping trip completed for \"{$list->name}\".");
    }

    private function shoppingAddItem(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('addItem', $list)) {
            return $denied;
        }

        $name = $request->get('name');
        if (! $name) {
            return Response::error('name is required for shopping_add_item.');
        }

        $data = array_filter([
            'name' => $name,
            'quantity' => $request->get('quantity'),
            'category' => $request->get('category'),
            'notes' => $request->get('notes'),
            'is_recurring' => $request->get('is_recurring'),
            'default_quantity' => $request->get('default_quantity'),
        ], fn ($v) => $v !== null);

        $item = app(ShoppingListService::class)->addItem($list, $data, $this->user());

        return Response::json([
            'message' => "Added \"{$item->name}\" to {$list->name}.",
            'item' => ['id' => $item->id, 'name' => $item->name, 'category' => $item->category],
        ]);
    }

    private function shoppingUpdateItem(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('updateItem', $item)) {
            return $denied;
        }

        $updates = $this->mergeUpdates(
            $request,
            simpleFields: ['name', 'is_recurring', 'sort_order'],
            nullableFields: ['quantity', 'category', 'notes'],
        );

        $item->update($updates);

        return Response::json([
            'message' => "Item \"{$item->name}\" updated.",
            'item' => ['id' => $item->id, 'name' => $item->name],
        ]);
    }

    private function shoppingRemoveItem(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('removeItem', $item)) {
            return $denied;
        }

        $name = $item->name;
        $item->delete();

        return Response::text("Removed \"{$name}\".");
    }

    private function shoppingCheckItem(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('checkItem', $item)) {
            return $denied;
        }

        app(ShoppingListService::class)->checkItem($item, $this->user());

        return Response::text("Checked \"{$item->name}\".");
    }

    private function shoppingUncheckItem(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('checkItem', $item)) {
            return $denied;
        }

        app(ShoppingListService::class)->uncheckItem($item);

        return Response::text("Unchecked \"{$item->name}\".");
    }

    private function shoppingMarkOnHand(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('markOnHand', $item)) {
            return $denied;
        }

        app(ShoppingListService::class)->markOnHand($item);

        return Response::text("Marked \"{$item->name}\" as on hand.");
    }

    private function shoppingClearOnHand(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('markOnHand', $item)) {
            return $denied;
        }

        app(ShoppingListService::class)->clearOnHand($item);

        return Response::text("Cleared on-hand status for \"{$item->name}\".");
    }

    private function shoppingMoveItem(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('moveItem', $item)) {
            return $denied;
        }

        $targetListId = $request->get('target_list_id');
        if (! $targetListId) {
            return Response::error('target_list_id is required for shopping_move_item.');
        }

        $target = ShoppingList::where('id', $targetListId)
            ->where('family_id', $this->familyId())
            ->first();

        if (! $target) {
            return Response::error("Target shopping list not found: {$targetListId}");
        }

        $moved = app(ShoppingListService::class)->moveItem($item, $target);

        return Response::text("Moved \"{$moved->name}\" to {$target->name}.");
    }

    private function shoppingToggleRecurring(Request $request): Response
    {
        $item = $this->findShoppingItem($request->get('item_id'));
        if ($item instanceof Response) {
            return $item;
        }

        if ($denied = $this->authorize('toggleRecurring', $item)) {
            return $denied;
        }

        $item = app(ShoppingListService::class)->toggleRecurring($item);

        return Response::json([
            'message' => $item->is_recurring ? "\"{$item->name}\" is now recurring." : "\"{$item->name}\" is no longer recurring.",
            'is_recurring' => (bool) $item->is_recurring,
        ]);
    }

    private function shoppingClearChecked(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('clearChecked', $list)) {
            return $denied;
        }

        $result = app(ShoppingListService::class)->clearChecked($list);

        return Response::json([
            'message' => "Cleared {$result['cleared']} item(s); reset {$result['recurring_reset']} recurring item(s).",
            'cleared' => $result['cleared'],
            'recurring_reset' => $result['recurring_reset'],
        ]);
    }

    private function shoppingAddRecipe(Request $request): Response
    {
        $list = $this->findShoppingList($request->get('list_id'));
        if ($list instanceof Response) {
            return $list;
        }

        if ($denied = $this->authorize('addRecipeToList', $list)) {
            return $denied;
        }

        $recipeId = $request->get('recipe_id');
        if (! $recipeId) {
            return Response::error('recipe_id is required for shopping_add_recipe.');
        }

        $recipe = Recipe::where('id', $recipeId)
            ->where('family_id', $this->familyId())
            ->first();

        if (! $recipe) {
            return Response::error("Recipe not found: {$recipeId}");
        }

        $items = app(ShoppingListService::class)->addRecipeIngredients(
            $list,
            $recipe,
            $this->user(),
            ingredientIds: $request->get('ingredient_ids'),
        );

        return Response::json([
            'message' => 'Added '.count($items)." item(s) from \"{$recipe->title}\" to {$list->name}.",
            'count' => count($items),
        ]);
    }

    private function shoppingSearchCatalog(Request $request): Response
    {
        $q = $request->get('q');
        if (! $q) {
            return Response::error('q is required for shopping_search_catalog.');
        }

        $catalogResults = ProductCatalog::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($q).'%'])
            ->orderByRaw('LOWER(name) ASC')
            ->limit(10)
            ->get(['name', 'category']);

        $historyResults = ShoppingItem::where('family_id', $this->familyId())
            ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($q).'%'])
            ->select('name', 'category')
            ->distinct()
            ->limit(5)
            ->get();

        $combined = $catalogResults->concat($historyResults)
            ->unique('name')
            ->take(15)
            ->values();

        return Response::json([
            'results' => $combined->map(fn ($r) => ['name' => $r->name, 'category' => $r->category])->toArray(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Meal plans
    // ─────────────────────────────────────────────────────────────────────────

    private function mealPlanList(Request $request): Response
    {
        if ($denied = $this->authorize('viewAny', MealPlan::class)) {
            return $denied;
        }

        $query = MealPlan::where('family_id', $this->familyId())
            ->with(['entries.recipe', 'entries.restaurant', 'entries.preset', 'shoppingList'])
            ->orderByDesc('week_start');

        if ($weekStart = $request->get('week_start')) {
            $query->where('week_start', $weekStart);
        }

        $plans = $query->get();

        return Response::json([
            'meal_plans' => $plans->map(fn ($p) => $this->summarizeMealPlan($p))->toArray(),
        ]);
    }

    private function mealPlanCurrent(): Response
    {
        if ($denied = $this->authorize('create', MealPlan::class)) {
            return $denied;
        }

        $plan = app(MealPlanService::class)->getCurrentWeekPlan($this->family(), $this->user());

        return Response::json(['meal_plan' => $this->summarizeMealPlan($plan)]);
    }

    private function mealPlanCreate(Request $request): Response
    {
        if ($denied = $this->authorize('create', MealPlan::class)) {
            return $denied;
        }

        $weekStart = $request->get('week_start');
        if (! $weekStart) {
            return Response::error('week_start is required for meal_plan_create.');
        }

        $plan = app(MealPlanService::class)->getOrCreatePlan($this->family(), $this->user(), $weekStart);

        return Response::json([
            'message' => "Meal plan created for week {$weekStart}.",
            'meal_plan' => $this->summarizeMealPlan($plan),
        ]);
    }

    private function mealPlanShow(Request $request): Response
    {
        $plan = $this->findMealPlan($request->get('plan_id'));
        if ($plan instanceof Response) {
            return $plan;
        }

        if ($denied = $this->authorize('view', $plan)) {
            return $denied;
        }

        $plan->load(['entries.recipe', 'entries.restaurant', 'entries.preset', 'shoppingList.items']);

        return Response::json(['meal_plan' => $this->summarizeMealPlan($plan, full: true)]);
    }

    private function mealPlanAddEntry(Request $request): Response
    {
        $plan = $this->findMealPlan($request->get('plan_id'));
        if ($plan instanceof Response) {
            return $plan;
        }

        if ($denied = $this->authorize('addEntry', $plan)) {
            return $denied;
        }

        $date = $request->get('date');
        $mealSlot = $request->get('meal_slot');
        if (! $date || ! $mealSlot) {
            return Response::error('date and meal_slot are required for meal_plan_add_entry.');
        }

        // An entry must have exactly one "source": recipe, restaurant, preset, or custom_title.
        $sources = array_filter([
            $request->get('recipe_id'),
            $request->get('restaurant_id'),
            $request->get('meal_preset_id'),
            $request->get('custom_title'),
        ], fn ($v) => $v !== null && $v !== '');

        if (count($sources) !== 1) {
            return Response::error('Exactly one of recipe_id, restaurant_id, meal_preset_id, or custom_title is required (got '.count($sources).').');
        }

        $data = array_filter([
            'date' => $date,
            'meal_slot' => $mealSlot,
            'recipe_id' => $request->get('recipe_id'),
            'restaurant_id' => $request->get('restaurant_id'),
            'meal_preset_id' => $request->get('meal_preset_id'),
            'custom_title' => $request->get('custom_title'),
            'notes' => $request->get('notes'),
            'servings' => $request->get('servings'),
            'assigned_cooks' => $request->get('assigned_cooks'),
            'sort_order' => $request->get('sort_order'),
            'acknowledge_allergens' => $request->get('acknowledge_allergens'),
        ], fn ($v) => $v !== null);

        try {
            $entry = app(MealPlanService::class)->addEntry($plan, $data, $this->user());
        } catch (AllergenAcknowledgementRequired $e) {
            // Surface the structured hit list so the MCP caller can prompt
            // the user explicitly before re-sending with acknowledge_allergens=true.
            return Response::json([
                'requires_acknowledgement' => true,
                'message' => $e->getMessage(),
                'hits' => $e->hits,
            ]);
        }

        return Response::json([
            'message' => "Added entry for {$date} {$mealSlot}.",
            'entry' => $this->summarizeMealEntry($entry),
        ]);
    }

    private function mealPlanUpdateEntry(Request $request): Response
    {
        $entryId = $request->get('entry_id');
        if (! $entryId) {
            return Response::error('entry_id is required for meal_plan_update_entry.');
        }

        $entry = MealPlanEntry::with('mealPlan')->find($entryId);
        if (! $entry || $entry->mealPlan->family_id !== $this->familyId()) {
            return Response::error("Meal plan entry not found: {$entryId}");
        }

        if ($denied = $this->authorize('updateEntry', $entry)) {
            return $denied;
        }

        $data = $this->mergeUpdates(
            $request,
            simpleFields: ['date', 'meal_slot', 'servings', 'assigned_cooks', 'sort_order'],
            refFields: ['recipe_id', 'restaurant_id', 'meal_preset_id'],
            nullableFields: ['notes', 'custom_title'],
        );

        $updated = app(MealPlanService::class)->updateEntry($entry, $data, $this->user());

        return Response::json([
            'message' => 'Meal plan entry updated.',
            'entry' => $this->summarizeMealEntry($updated),
        ]);
    }

    private function mealPlanRemoveEntry(Request $request): Response
    {
        $entryId = $request->get('entry_id');
        if (! $entryId) {
            return Response::error('entry_id is required for meal_plan_remove_entry.');
        }

        $entry = MealPlanEntry::with('mealPlan')->find($entryId);
        if (! $entry || $entry->mealPlan->family_id !== $this->familyId()) {
            return Response::error("Meal plan entry not found: {$entryId}");
        }

        if ($denied = $this->authorize('deleteEntry', $entry)) {
            return $denied;
        }

        app(MealPlanService::class)->removeEntry($entry);

        return Response::text('Meal plan entry removed.');
    }

    private function mealPlanMoveEntry(Request $request): Response
    {
        $entryId = $request->get('entry_id');
        if (! $entryId) {
            return Response::error('entry_id is required for meal_plan_move_entry.');
        }

        $entry = MealPlanEntry::with('mealPlan')->find($entryId);
        if (! $entry || $entry->mealPlan->family_id !== $this->familyId()) {
            return Response::error("Meal plan entry not found: {$entryId}");
        }

        if ($denied = $this->authorize('updateEntry', $entry)) {
            return $denied;
        }

        $date = $request->get('date');
        $mealSlot = $request->get('meal_slot');
        if (! $date || ! $mealSlot) {
            return Response::error('date and meal_slot are required for meal_plan_move_entry.');
        }

        $updated = app(MealPlanService::class)->moveEntry($entry, $date, $mealSlot);

        return Response::json([
            'message' => "Moved entry to {$date} {$mealSlot}.",
            'entry' => $this->summarizeMealEntry($updated),
        ]);
    }

    private function mealPlanPreviewShopping(Request $request): Response
    {
        $plan = $this->findMealPlan($request->get('plan_id'));
        if ($plan instanceof Response) {
            return $plan;
        }

        if ($denied = $this->authorize('view', $plan)) {
            return $denied;
        }

        $days = (int) ($request->get('days') ?? 7);
        $start = $request->get('start') ? Carbon::parse($request->get('start')) : Carbon::today();
        $end = (clone $start)->addDays($days - 1);

        $service = app(MealPlanService::class);
        $entries = $service->entriesWithIngredientsInRange($plan, $start->toDateString(), $end->toDateString());
        $existingNames = $service->existingShoppingItemNames($plan, $request->get('shopping_list_id'));

        return Response::json([
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'entries' => $entries->map(fn (MealPlanEntry $e) => [
                'entry_id' => $e->id,
                'date' => $e->date->toDateString(),
                'meal_slot' => $e->meal_slot?->value,
                'recipe' => ['id' => $e->recipe->id, 'title' => $e->recipe->title],
                'ingredients' => $e->recipe->ingredients->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity, 'unit' => $i->unit,
                    'preparation' => $i->preparation, 'is_optional' => (bool) $i->is_optional,
                    'already_on_list' => in_array(strtolower(trim($i->name)), $existingNames, true),
                ])->toArray(),
            ])->toArray(),
        ]);
    }

    private function mealPlanAddToShopping(Request $request): Response
    {
        $plan = $this->findMealPlan($request->get('plan_id'));
        if ($plan instanceof Response) {
            return $plan;
        }

        if ($denied = $this->authorize('update', $plan)) {
            return $denied;
        }

        $selections = $request->get('selections');
        if (! $selections || ! is_array($selections) || empty($selections)) {
            return Response::error('selections is required for meal_plan_add_to_shopping (array of {entry_id, ingredient_ids?}).');
        }

        $added = app(MealPlanService::class)->addSelectionsToShoppingList(
            $plan,
            $this->user(),
            $selections,
            $request->get('shopping_list_id'),
        );

        return Response::json([
            'message' => "Added {$added} item(s) to shopping list.",
            'added_count' => $added,
        ]);
    }

    private function mealPlanGenerateShopping(Request $request): Response
    {
        $plan = $this->findMealPlan($request->get('plan_id'));
        if ($plan instanceof Response) {
            return $plan;
        }

        if ($denied = $this->authorize('update', $plan)) {
            return $denied;
        }

        app(MealPlanService::class)->generateShoppingList($plan, $this->user());

        return Response::text('Shopping list generated for meal plan.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Meal presets
    // ─────────────────────────────────────────────────────────────────────────

    private function mealPresetList(): Response
    {
        $presets = MealPreset::where('family_id', $this->familyId())
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return Response::json([
            'presets' => $presets->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->label,
                'icon' => $p->icon,
                'sort_order' => $p->sort_order,
                'is_system' => (bool) $p->is_system,
            ])->toArray(),
        ]);
    }

    private function mealPresetCreate(Request $request): Response
    {
        if ($denied = $this->authorize('managePresets', MealPlan::class)) {
            return $denied;
        }

        $label = $request->get('label');
        if (! $label) {
            return Response::error('label is required for meal_preset_create.');
        }

        $preset = MealPreset::create([
            'family_id' => $this->familyId(),
            'label' => $label,
            'icon' => $request->get('icon'),
            'sort_order' => $request->get('sort_order', 0),
            'is_system' => false,
        ]);

        return Response::json([
            'message' => "Preset \"{$preset->label}\" created.",
            'preset' => ['id' => $preset->id, 'label' => $preset->label],
        ]);
    }

    private function mealPresetUpdate(Request $request): Response
    {
        if ($denied = $this->authorize('managePresets', MealPlan::class)) {
            return $denied;
        }

        $presetId = $request->get('preset_id');
        if (! $presetId) {
            return Response::error('preset_id is required for meal_preset_update.');
        }

        $preset = MealPreset::where('family_id', $this->familyId())->find($presetId);
        if (! $preset) {
            return Response::error("Meal preset not found: {$presetId}");
        }

        $updates = array_filter([
            'label' => $request->get('label'),
            'icon' => $request->get('icon'),
            'sort_order' => $request->get('sort_order'),
        ], fn ($v) => $v !== null);

        $preset->update($updates);

        return Response::json([
            'message' => "Preset \"{$preset->label}\" updated.",
            'preset' => ['id' => $preset->id, 'label' => $preset->label],
        ]);
    }

    private function mealPresetDelete(Request $request): Response
    {
        if ($denied = $this->authorize('managePresets', MealPlan::class)) {
            return $denied;
        }

        $presetId = $request->get('preset_id');
        if (! $presetId) {
            return Response::error('preset_id is required for meal_preset_delete.');
        }

        $preset = MealPreset::where('family_id', $this->familyId())->find($presetId);
        if (! $preset) {
            return Response::error("Meal preset not found: {$presetId}");
        }

        if ($preset->is_system) {
            return Response::error('System presets cannot be deleted.');
        }

        $label = $preset->label;
        $preset->delete();

        return Response::text("Preset \"{$label}\" deleted.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Restaurants
    // ─────────────────────────────────────────────────────────────────────────

    private function restaurantList(Request $request): Response
    {
        $family = $this->family();
        $tagId = $request->get('tag');

        $query = $family->restaurants()
            ->with([
                'tags' => fn ($q) => $q->where('tags.family_id', $family->id),
                'ratings' => fn ($q) => $q->whereHas('user', fn ($u) => $u->where('family_id', $family->id)),
            ]);

        if ($tagId) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        $restaurants = $query->get()->map(function (Restaurant $r) use ($family) {
            return [
                'id' => $r->id,
                'name' => $r->name,
                'address' => $r->address,
                'phone' => $r->phone,
                'menu_url' => $r->menu_url,
                'google_maps_url' => $r->google_maps_url,
                'image_url' => $r->image_url,
                'notes' => $r->pivot->notes,
                'is_favorite' => (bool) ($r->pivot->is_favorite ?? false),
                'family_average_rating' => $r->familyAverageRating($family->id),
                'tags' => $r->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->toArray(),
            ];
        });

        return Response::json(['restaurants' => $restaurants->toArray()]);
    }

    private function restaurantShow(Request $request): Response
    {
        $restaurantId = $request->get('restaurant_id');
        if (! $restaurantId) {
            return Response::error('restaurant_id is required for restaurant_show.');
        }

        $family = $this->family();
        $rest = $family->restaurants()
            ->with([
                'tags' => fn ($q) => $q->where('tags.family_id', $family->id),
                'ratings' => fn ($q) => $q->with('user')->whereHas('user', fn ($u) => $u->where('family_id', $family->id)),
            ])
            ->find($restaurantId);

        if (! $rest) {
            return Response::error("Restaurant not found: {$restaurantId}");
        }

        return Response::json([
            'restaurant' => [
                'id' => $rest->id,
                'name' => $rest->name,
                'address' => $rest->address,
                'phone' => $rest->phone,
                'menu_url' => $rest->menu_url,
                'google_maps_url' => $rest->google_maps_url,
                'image_url' => $rest->image_url,
                'notes' => $rest->pivot->notes,
                'is_favorite' => (bool) ($rest->pivot->is_favorite ?? false),
                'family_average_rating' => $rest->familyAverageRating($family->id),
                'tags' => $rest->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->toArray(),
                'ratings' => $rest->ratings->map(fn ($r) => [
                    'user' => $r->user?->name, 'score' => $r->score,
                ])->toArray(),
            ],
        ]);
    }

    private function restaurantCreate(Request $request): Response
    {
        if ($denied = $this->authorize('manageRestaurants', MealPlan::class)) {
            return $denied;
        }

        $name = $request->get('name');
        if (! $name) {
            return Response::error('name is required for restaurant_create.');
        }

        $tagIds = $request->get('tag_ids', []);

        $restaurant = Restaurant::create(array_filter([
            'name' => $name,
            'address' => $request->get('address'),
            'phone' => $request->get('phone'),
            'google_maps_url' => $request->get('google_maps_url'),
            'menu_url' => $request->get('menu_url'),
            'image_url' => $request->get('image_url'),
        ], fn ($v) => $v !== null));

        FamilyRestaurant::create([
            'family_id' => $this->familyId(),
            'restaurant_id' => $restaurant->id,
        ]);

        if ($tagIds) {
            $restaurant->tags()->sync($this->scopedTagIds($tagIds));
        }

        return Response::json([
            'message' => "Restaurant \"{$restaurant->name}\" added.",
            'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->name],
        ]);
    }

    private function restaurantUpdate(Request $request): Response
    {
        if ($denied = $this->authorize('manageRestaurants', MealPlan::class)) {
            return $denied;
        }

        $restaurantId = $request->get('restaurant_id');
        if (! $restaurantId) {
            return Response::error('restaurant_id is required for restaurant_update.');
        }

        $family = $this->family();
        $rest = $family->restaurants()->find($restaurantId);
        if (! $rest) {
            return Response::error("Restaurant not found: {$restaurantId}");
        }

        $coreFields = ['name', 'address', 'phone', 'google_maps_url', 'menu_url', 'image_url'];
        $coreData = [];
        foreach ($coreFields as $f) {
            if ($request->get($f) !== null) {
                $coreData[$f] = $request->get($f);
            }
        }
        if ($coreData) {
            $rest->update($coreData);
        }

        $pivotData = [];
        if ($request->get('notes') !== null) {
            $pivotData['notes'] = $request->get('notes');
        }
        if ($request->get('is_favorite') !== null) {
            $pivotData['is_favorite'] = (bool) $request->get('is_favorite');
        }
        if ($pivotData) {
            FamilyRestaurant::where('family_id', $family->id)
                ->where('restaurant_id', $rest->id)
                ->update($pivotData);
        }

        if ($request->get('tag_ids') !== null) {
            $rest->tags()->sync($this->scopedTagIds($request->get('tag_ids', [])));
        }

        return Response::json([
            'message' => "Restaurant \"{$rest->name}\" updated.",
            'restaurant' => ['id' => $rest->id, 'name' => $rest->name],
        ]);
    }

    private function restaurantDelete(Request $request): Response
    {
        if ($denied = $this->authorize('manageRestaurants', MealPlan::class)) {
            return $denied;
        }

        $restaurantId = $request->get('restaurant_id');
        if (! $restaurantId) {
            return Response::error('restaurant_id is required for restaurant_delete.');
        }

        $pivot = FamilyRestaurant::where('family_id', $this->familyId())
            ->where('restaurant_id', $restaurantId)
            ->first();

        if (! $pivot) {
            return Response::error("Restaurant not linked to this family: {$restaurantId}");
        }

        $pivot->delete();

        return Response::text('Restaurant unlinked from family.');
    }

    private function restaurantImport(Request $request): Response
    {
        if ($denied = $this->authorize('manageRestaurants', MealPlan::class)) {
            return $denied;
        }

        $url = $request->get('url');
        if (! $url) {
            return Response::error('url is required for restaurant_import.');
        }

        $service = app(RestaurantImportService::class);
        $preview = (bool) $request->get('preview', false);

        try {
            if ($preview) {
                $extracted = $service->extractFromUrl($url);

                return Response::json(['preview' => $extracted]);
            }

            $restaurant = $service->importFromUrl($url, $this->family());
        } catch (\Exception $e) {
            return Response::error('Import failed: '.$e->getMessage());
        }

        return Response::json([
            'message' => "Restaurant \"{$restaurant->name}\" imported.",
            'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->name],
        ]);
    }

    private function restaurantRate(Request $request): Response
    {
        if ($denied = $this->authorize('manageRestaurants', MealPlan::class)) {
            return $denied;
        }

        $restaurantId = $request->get('restaurant_id');
        $score = $request->get('score');
        if (! $restaurantId || ! $score) {
            return Response::error('restaurant_id and score are required for restaurant_rate.');
        }

        if ($score < 1 || $score > 5) {
            return Response::error('score must be 1-5.');
        }

        $family = $this->family();
        $rest = $family->restaurants()->find($restaurantId);
        if (! $rest) {
            return Response::error("Restaurant not found: {$restaurantId}");
        }

        $rating = Rating::updateOrCreate(
            [
                'user_id' => $this->user()->id,
                'rateable_type' => Restaurant::class,
                'rateable_id' => $rest->id,
            ],
            [
                'family_id' => $family->id,
                'score' => (int) $score,
            ]
        );

        return Response::json([
            'message' => "Rated \"{$rest->name}\" {$score}/5.",
            'rating' => ['id' => $rating->id, 'score' => $rating->score],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function findRecipe(?string $id): Recipe|Response
    {
        if (! $id) {
            return Response::error('recipe_id is required.');
        }

        $recipe = Recipe::where('family_id', $this->familyId())->find($id);
        if (! $recipe) {
            return Response::error("Recipe not found: {$id}");
        }

        return $recipe;
    }

    private function findShoppingList(?string $id): ShoppingList|Response
    {
        if (! $id) {
            return Response::error('list_id is required.');
        }

        $list = ShoppingList::where('family_id', $this->familyId())->find($id);
        if (! $list) {
            return Response::error("Shopping list not found: {$id}");
        }

        return $list;
    }

    private function findShoppingItem(?string $id): ShoppingItem|Response
    {
        if (! $id) {
            return Response::error('item_id is required.');
        }

        $item = ShoppingItem::where('family_id', $this->familyId())->find($id);
        if (! $item) {
            return Response::error("Shopping item not found: {$id}");
        }

        return $item;
    }

    private function findMealPlan(?string $id): MealPlan|Response
    {
        if (! $id) {
            return Response::error('plan_id is required.');
        }

        $plan = MealPlan::where('family_id', $this->familyId())->find($id);
        if (! $plan) {
            return Response::error("Meal plan not found: {$id}");
        }

        return $plan;
    }

    private function summarizeRecipe(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'prep_time_minutes' => $r->prep_time_minutes,
            'cook_time_minutes' => $r->cook_time_minutes,
            'servings' => $r->servings,
            'is_favorite' => (bool) $r->is_favorite,
            'image_path' => $r->image_path,
            'tags' => $r->relationLoaded('tags') ? $r->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->toArray() : null,
        ];
    }

    private function summarizeMealPlan(MealPlan $p, bool $full = false): array
    {
        $base = [
            'id' => $p->id,
            'week_start' => $p->week_start instanceof \DateTimeInterface ? $p->week_start->format('Y-m-d') : $p->week_start,
            'shopping_list_id' => $p->shoppingList?->id,
            'entry_count' => $p->relationLoaded('entries') ? $p->entries->count() : null,
        ];

        if ($full && $p->relationLoaded('entries')) {
            $base['entries'] = $p->entries->map(fn ($e) => $this->summarizeMealEntry($e))->toArray();
        }

        return $base;
    }

    private function summarizeMealEntry(MealPlanEntry $e): array
    {
        return [
            'id' => $e->id,
            'date' => $e->date instanceof \DateTimeInterface ? $e->date->toDateString() : $e->date,
            'meal_slot' => $e->meal_slot instanceof MealSlot ? $e->meal_slot->value : $e->meal_slot,
            'recipe' => $e->recipe ? ['id' => $e->recipe->id, 'title' => $e->recipe->title] : null,
            'restaurant' => $e->restaurant ? ['id' => $e->restaurant->id, 'name' => $e->restaurant->name] : null,
            'preset' => $e->preset ? ['id' => $e->preset->id, 'label' => $e->preset->label] : null,
            'notes' => $e->notes,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Allergens (reference data) + per-member allergy profiles + recipe-allergen
    // single-row edits + AI backfill + public sharing. See v1.10.0 release.
    // ─────────────────────────────────────────────────────────────────────────

    private function allergenList(): Response
    {
        $allergens = Allergen::availableToFamily($this->familyId())
            ->orderByDesc('is_big_nine')
            ->orderBy('name')
            ->get(['id', 'family_id', 'name', 'slug', 'is_big_nine']);

        return Response::json(['allergens' => $allergens]);
    }

    private function allergenCreate(Request $request): Response
    {
        if (! $this->user()->isParent()) {
            return Response::error('Only parents can add custom allergens.');
        }
        $name = trim((string) $request->get('name', ''));
        if ($name === '') {
            return Response::error('name is required for allergen_create.');
        }
        $slug = Str::slug($name);
        if ($slug === '') {
            return Response::error('Name must contain at least one alphanumeric character.');
        }
        $exists = Allergen::availableToFamily($this->familyId())->where('slug', $slug)->exists();
        if ($exists) {
            return Response::error('An allergen with that name already exists.');
        }
        $allergen = Allergen::create([
            'family_id' => $this->familyId(),
            'name' => $name,
            'slug' => $slug,
            'is_big_nine' => false,
        ]);

        return Response::json(['allergen' => $allergen->only(['id', 'family_id', 'name', 'slug', 'is_big_nine'])]);
    }

    private function allergenUpdate(Request $request): Response
    {
        $allergen = Allergen::find($request->get('allergen_id'));
        if (! $allergen) {
            return Response::error('Allergen not found.');
        }
        if ($allergen->family_id === null) {
            return Response::error('Big 9 allergens are immutable.');
        }
        if ((string) $allergen->family_id !== (string) $this->familyId() || ! $this->user()->isParent()) {
            return Response::error('Not authorized to modify this allergen.');
        }
        $name = trim((string) $request->get('name', ''));
        if ($name === '') {
            return Response::error('name is required for allergen_update.');
        }
        $slug = Str::slug($name);
        $dup = Allergen::availableToFamily($this->familyId())
            ->where('slug', $slug)->where('id', '!=', $allergen->id)->exists();
        if ($dup) {
            return Response::error('An allergen with that name already exists.');
        }
        $allergen->update(['name' => $name, 'slug' => $slug]);

        return Response::json(['allergen' => $allergen->only(['id', 'family_id', 'name', 'slug', 'is_big_nine'])]);
    }

    private function allergenDelete(Request $request): Response
    {
        $allergen = Allergen::find($request->get('allergen_id'));
        if (! $allergen) {
            return Response::error('Allergen not found.');
        }
        if ($allergen->family_id === null) {
            return Response::error('Big 9 allergens cannot be removed.');
        }
        if ((string) $allergen->family_id !== (string) $this->familyId() || ! $this->user()->isParent()) {
            return Response::error('Not authorized.');
        }
        $allergen->delete();

        return Response::json(['deleted' => true]);
    }

    private function allergenProfileShow(Request $request): Response
    {
        $member = $this->resolveFamilyMember($request->get('user_id'));
        if ($member instanceof Response) {
            return $member;
        }
        if (! (new UserAllergenPolicy)->view($this->user(), $member)) {
            return Response::error('Not authorized.');
        }

        return Response::json([
            'member_id' => $member->id,
            'allergen_ids' => $member->allergens()->pluck('allergens.id')->values(),
            'reviewed_at' => optional($member->allergen_profile_reviewed_at)->toIso8601String(),
        ]);
    }

    private function allergenProfileSet(Request $request): Response
    {
        $member = $this->resolveFamilyMember($request->get('user_id'));
        if ($member instanceof Response) {
            return $member;
        }
        if (! (new UserAllergenPolicy)->update($this->user(), $member)) {
            return Response::error('Not authorized.');
        }
        $ids = (array) $request->get('allergen_ids', []);
        $valid = Allergen::availableToFamily($this->familyId())
            ->whereIn('id', $ids)->pluck('id')->all();
        if (count($valid) !== count($ids)) {
            return Response::error('One or more allergens are not available to this family.');
        }
        $member->allergens()->sync($valid);
        $member->forceFill(['allergen_profile_reviewed_at' => now()])->save();

        return Response::json([
            'member_id' => $member->id,
            'allergen_ids' => $member->allergens()->pluck('allergens.id')->values(),
            'reviewed_at' => $member->allergen_profile_reviewed_at->toIso8601String(),
        ]);
    }

    private function allergenProfileMarkReviewed(Request $request): Response
    {
        $member = $this->resolveFamilyMember($request->get('user_id'));
        if ($member instanceof Response) {
            return $member;
        }
        if (! (new UserAllergenPolicy)->update($this->user(), $member)) {
            return Response::error('Not authorized.');
        }
        $member->forceFill(['allergen_profile_reviewed_at' => now()])->save();

        return Response::json([
            'member_id' => $member->id,
            'reviewed_at' => $member->allergen_profile_reviewed_at->toIso8601String(),
        ]);
    }

    private function recipeAllergenAdd(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }
        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }
        $allergenId = $request->get('allergen_id');
        $presence = $request->get('presence');
        if (! $allergenId || ! in_array($presence, ['contains', 'may_contain'], true)) {
            return Response::error('allergen_id and presence (contains|may_contain) are required.');
        }
        $allergen = Allergen::availableToFamily($recipe->family_id)->where('id', $allergenId)->first();
        if (! $allergen) {
            return Response::error('Allergen is not available to this family.');
        }
        $row = RecipeAllergen::firstOrCreate([
            'recipe_id' => $recipe->id,
            'allergen_id' => $allergen->id,
            'presence' => $presence,
        ], [
            'id' => (string) Str::uuid(),
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->user()->id,
            'confirmed_at' => now(),
        ]);

        return Response::json(['allergen' => $row->only(['id', 'recipe_id', 'allergen_id', 'presence', 'source'])]);
    }

    private function recipeAllergenPatch(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }
        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }
        $row = RecipeAllergen::where('recipe_id', $recipe->id)
            ->where('id', $request->get('row_id'))
            ->first();
        if (! $row) {
            return Response::error('Recipe-allergen row not found.');
        }
        if ($request->get('action') === 'remove') {
            $row->delete();

            return Response::json(['removed' => true]);
        }
        $update = [
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $this->user()->id,
            'confirmed_at' => now(),
        ];
        if (in_array($request->get('presence'), ['contains', 'may_contain'], true)) {
            $update['presence'] = $request->get('presence');
        }
        $row->update($update);

        return Response::json(['allergen' => $row->fresh()->only(['id', 'recipe_id', 'allergen_id', 'presence', 'source'])]);
    }

    private function recipeAllergenBackfill(Request $request): Response
    {
        if (! $this->user()->isParent()) {
            return Response::error('Only parents can run the allergen backfill.');
        }
        $service = app(RecipeImportService::class);
        $force = (bool) $request->get('force', false);
        $count = $service->queueAllergenBackfill((string) $this->familyId(), $force);

        return Response::json([
            'queued' => $count,
            'message' => "Queued {$count} recipe(s) for AI allergen tagging.",
        ]);
    }

    private function recipeShare(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }
        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }
        if (! $recipe->isShared()) {
            do {
                $token = Str::random(22);
            } while (Recipe::where('share_token', $token)->exists());
            $recipe->forceFill(['share_token' => $token])->save();
        }
        if ($request->has('visible_attribution')) {
            $recipe->forceFill(['share_visible_attribution' => (bool) $request->get('visible_attribution')])->save();
        }

        return Response::json([
            'is_shared' => true,
            'url' => $recipe->shareUrl(),
            'visible_attribution' => (bool) $recipe->share_visible_attribution,
        ]);
    }

    private function recipeShareUpdate(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }
        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }
        if (! $recipe->isShared()) {
            return Response::error('Recipe is not shared yet.');
        }
        $recipe->forceFill([
            'share_visible_attribution' => (bool) $request->get('visible_attribution'),
        ])->save();

        return Response::json([
            'is_shared' => true,
            'url' => $recipe->shareUrl(),
            'visible_attribution' => (bool) $recipe->share_visible_attribution,
        ]);
    }

    private function recipeUnshare(Request $request): Response
    {
        $recipe = $this->findRecipe($request->get('recipe_id'));
        if ($recipe instanceof Response) {
            return $recipe;
        }
        if ($denied = $this->authorize('update', $recipe)) {
            return $denied;
        }
        $recipe->forceFill([
            'share_token' => null,
            'share_visible_attribution' => false,
        ])->save();

        return Response::json([
            'is_shared' => false,
            'url' => null,
            'visible_attribution' => false,
        ]);
    }

    /**
     * Resolve a user by id and confirm they belong to the calling family.
     * Returns the User or a 404-equivalent Response::error.
     */
    private function resolveFamilyMember(?string $userId): User|Response
    {
        if (! $userId) {
            return Response::error('user_id is required.');
        }
        $member = User::where('id', $userId)
            ->where('family_id', $this->familyId())
            ->first();
        if (! $member) {
            return Response::error('Family member not found.');
        }

        return $member;
    }

    private function scopedTagIds(array $tagIds): array
    {
        if (empty($tagIds)) {
            return [];
        }

        return Tag::whereIn('id', $tagIds)
            ->where('family_id', $this->familyId())
            ->pluck('id')
            ->all();
    }
}
