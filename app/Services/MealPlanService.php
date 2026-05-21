<?php

namespace App\Services;

use App\Exceptions\AllergenAcknowledgementRequired;
use App\Models\Family;
use App\Models\MealPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MealPlanService
{
    public function __construct(
        private ShoppingListService $shoppingListService,
    ) {}

    /**
     * Get or create the plan for the current week (uses family's week_start_day setting).
     */
    public function getCurrentWeekPlan(Family $family, User $user): MealPlan
    {
        $weekStart = Carbon::now()->startOfWeek($family->getWeekStartDay())->toDateString();

        return $this->getOrCreatePlan($family, $user, $weekStart);
    }

    /**
     * Get or create a plan for a specific week.
     */
    public function getOrCreatePlan(Family $family, User $user, string $weekStart): MealPlan
    {
        $plan = MealPlan::where('family_id', $family->id)
            ->whereDate('week_start', $weekStart)
            ->first();

        if (! $plan) {
            $plan = MealPlan::create([
                'family_id' => $family->id,
                'week_start' => $weekStart,
                'created_by' => $user->id,
            ]);
        }

        return $plan->load(['entries.recipe', 'entries.restaurant', 'entries.preset', 'shoppingList']);
    }

    /**
     * Add an entry to a meal plan. Triggers shopping list and task cascades.
     *
     * Refuses with AllergenAcknowledgementRequired if the recipe carries any
     * allergen for any family member with a reviewed profile, unless the
     * caller has explicitly set `acknowledge_allergens => true`.
     */
    public function addEntry(MealPlan $plan, array $data, User $user): MealPlanEntry
    {
        if (! empty($data['recipe_id']) && empty($data['acknowledge_allergens'])) {
            $recipe = Recipe::with('allergens')->find($data['recipe_id']);
            if ($recipe) {
                $hits = $this->allergenHitsForFamily($plan->family_id, $recipe);
                if (! empty($hits)) {
                    throw new AllergenAcknowledgementRequired($hits);
                }
            }
        }

        $entry = MealPlanEntry::create([
            'meal_plan_id' => $plan->id,
            'recipe_id' => $data['recipe_id'] ?? null,
            'restaurant_id' => $data['restaurant_id'] ?? null,
            'meal_preset_id' => $data['meal_preset_id'] ?? null,
            'custom_title' => $data['custom_title'] ?? null,
            'date' => $data['date'],
            'meal_slot' => $data['meal_slot'],
            'servings' => $data['servings'] ?? null,
            'assigned_cooks' => $data['assigned_cooks'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        // Shopping list cascade: only for recipe entries
        if ($entry->recipe_id) {
            $list = $this->ensureShoppingList($plan, $user);
            $this->shoppingListService->addRecipeIngredients(
                $list,
                $entry->recipe,
                $user,
                $entry->id,
                $entry->date->toDateString()
            );
        }

        // Cook assignment cascade: create tasks for assigned cooks
        if (! empty($entry->assigned_cooks)) {
            $this->createCookTasks($entry, $plan, $user);
        }

        return $entry->load(['recipe', 'restaurant', 'preset']);
    }

    /**
     * Update an entry. Diff-based cascade for shopping items and tasks.
     */
    public function updateEntry(MealPlanEntry $entry, array $data, User $user): MealPlanEntry
    {
        $oldRecipeId = $entry->recipe_id;
        $oldServings = $entry->servings;
        $oldCooks = $entry->assigned_cooks ?? [];
        $plan = $entry->mealPlan;

        $entry->update($data);
        $entry->refresh();

        $newRecipeId = $entry->recipe_id;
        $recipeChanged = $oldRecipeId !== $newRecipeId;
        $servingsChanged = $oldServings !== $entry->servings;

        // Shopping list cascade
        if ($plan->shopping_list_id) {
            $list = $plan->shoppingList;

            if ($recipeChanged) {
                // Remove old recipe items
                if ($oldRecipeId) {
                    $this->shoppingListService->removeRecipeItems($list, $entry->id);
                }
                // Add new recipe items
                if ($newRecipeId) {
                    $this->shoppingListService->addRecipeIngredients(
                        $list,
                        $entry->recipe,
                        $user,
                        $entry->id,
                        $entry->date->toDateString()
                    );
                }
            } elseif ($servingsChanged && $newRecipeId) {
                // Re-add with new servings (remove + add)
                $this->shoppingListService->removeRecipeItems($list, $entry->id);
                $this->shoppingListService->addRecipeIngredients(
                    $list,
                    $entry->recipe,
                    $user,
                    $entry->id,
                    $entry->date->toDateString()
                );
            }
        }

        // Cook task cascade
        $newCooks = $entry->assigned_cooks ?? [];
        if ($oldCooks !== $newCooks) {
            $removed = array_diff($oldCooks, $newCooks);
            $added = array_diff($newCooks, $oldCooks);

            // Delete tasks for removed cooks
            if (! empty($removed)) {
                Task::where('source_type', MealPlanEntry::class)
                    ->where('source_id', $entry->id)
                    ->whereIn('assigned_to', $removed)
                    ->delete();
            }

            // Create tasks for newly added cooks
            foreach ($added as $cookId) {
                $this->createSingleCookTask($entry, $plan, $user, $cookId);
            }
        }

        // Update task titles if the entry type/recipe changed
        if ($recipeChanged) {
            Task::where('source_type', MealPlanEntry::class)
                ->where('source_id', $entry->id)
                ->update(['title' => $this->getCookTaskTitle($entry)]);
        }

        return $entry->load(['recipe', 'restaurant', 'preset']);
    }

    /**
     * Remove an entry and cascade-delete shopping items + tasks.
     */
    public function removeEntry(MealPlanEntry $entry): void
    {
        $plan = $entry->mealPlan;

        // Remove shopping items linked to this entry
        if ($entry->recipe_id && $plan->shopping_list_id) {
            $this->shoppingListService->removeRecipeItems($plan->shoppingList, $entry->id);
        }

        // Delete all linked cook tasks
        Task::where('source_type', MealPlanEntry::class)
            ->where('source_id', $entry->id)
            ->delete();

        $entry->delete();
    }

    /**
     * Move an entry to a different day/slot.
     */
    public function moveEntry(MealPlanEntry $entry, string $date, string $mealSlot): MealPlanEntry
    {
        $entry->update([
            'date' => $date,
            'meal_slot' => $mealSlot,
        ]);

        // Update linked tasks' due dates
        Task::where('source_type', MealPlanEntry::class)
            ->where('source_id', $entry->id)
            ->update(['due_date' => $date]);

        // Update shopping items' needed dates
        if ($entry->recipe_id && $entry->mealPlan->shopping_list_id) {
            $entry->mealPlan->shoppingList->items()
                ->where('meal_plan_entry_id', $entry->id)
                ->update(['needed_date' => $date]);
        }

        return $entry->fresh()->load(['recipe', 'restaurant', 'preset']);
    }

    /**
     * Return recipe-bearing entries scheduled within [from, to] (inclusive),
     * eagerly loading the recipe + its ingredients. Used by the
     * "preview before adding to shopping list" flow on the meal-plan page.
     *
     * @return Collection<int, MealPlanEntry>
     */
    public function entriesWithIngredientsInRange(MealPlan $plan, string $from, string $to)
    {
        return $plan->entries()
            ->whereNotNull('recipe_id')
            ->whereBetween('date', [$from, $to])
            ->with(['recipe.ingredients' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('date')
            ->orderBy('meal_slot')
            ->get();
    }

    /**
     * Lowercase, trimmed names of every item already on the shopping list the
     * user is targeting (or the plan's auto-list if none specified). Used to
     * flag duplicates in the preview UI.
     *
     * @return array<int, string>
     */
    public function existingShoppingItemNames(MealPlan $plan, ?string $shoppingListId = null): array
    {
        $list = $this->resolveTargetList($plan, $shoppingListId);

        if (! $list) {
            return [];
        }

        return $list->items()
            ->pluck('name')
            ->map(fn ($name) => strtolower(trim($name)))
            ->all();
    }

    /**
     * Detect (member, allergen) hits between a recipe and the reviewed allergy
     * profiles of the recipe's family. Members without a reviewed profile are
     * never returned — silently filtering against an unknown profile would be
     * worse than warning the user explicitly.
     *
     * @return array<int, array{member_id: string, member_name: string, allergen_id: string, allergen_name: string, presence: string}>
     */
    public function allergenHitsForFamily(string $familyId, Recipe $recipe): array
    {
        $recipe->loadMissing('allergens');

        if ($recipe->allergens->isEmpty()) {
            return [];
        }

        $recipeAllergenIds = $recipe->allergens->pluck('id');

        $rows = DB::table('member_allergens')
            ->join('users', 'users.id', '=', 'member_allergens.user_id')
            ->join('allergens', 'allergens.id', '=', 'member_allergens.allergen_id')
            ->where('users.family_id', $familyId)
            ->whereNotNull('users.allergen_profile_reviewed_at')
            ->whereIn('member_allergens.allergen_id', $recipeAllergenIds)
            ->get([
                'users.id as member_id',
                'users.name as member_name',
                'allergens.id as allergen_id',
                'allergens.name as allergen_name',
            ]);

        $presenceByAllergen = $recipe->allergens
            ->mapWithKeys(fn ($a) => [
                $a->id => $a->getRelationValue('pivot')->presence->value,
            ]);

        return $rows->map(fn ($row) => [
            'member_id' => (string) $row->member_id,
            'member_name' => $row->member_name,
            'allergen_id' => (string) $row->allergen_id,
            'allergen_name' => $row->allergen_name,
            'presence' => $presenceByAllergen[$row->allergen_id] ?? 'contains',
        ])->all();
    }

    /**
     * Resolve which shopping list a "target" (preview/add) operation should use.
     * Falls back to the plan's auto-linked list. Returns null if neither exists
     * (e.g. preview before any list is created).
     */
    private function resolveTargetList(MealPlan $plan, ?string $shoppingListId = null): ?ShoppingList
    {
        if ($shoppingListId) {
            return ShoppingList::where('family_id', $plan->family_id)->find($shoppingListId);
        }

        return $plan->shoppingList;
    }

    /**
     * Add a user-curated selection of recipe ingredients to a shopping list.
     * `selections` is a list of { entry_id, ingredient_ids: array<string>|null } —
     * null/missing ingredient_ids means "all ingredients on this entry".
     *
     * If `$shoppingListId` is provided and belongs to the plan's family, items
     * are added to that list. Otherwise the plan's auto-linked list is used
     * (created on demand).
     *
     * Returns the count of (entry, ingredient) pairs actually added.
     */
    public function addSelectionsToShoppingList(MealPlan $plan, User $user, array $selections, ?string $shoppingListId = null): int
    {
        $list = $shoppingListId
            ? (ShoppingList::where('family_id', $plan->family_id)->find($shoppingListId) ?? $this->ensureShoppingList($plan, $user))
            : $this->ensureShoppingList($plan, $user);

        $added = 0;

        $entryIds = array_filter(array_column($selections, 'entry_id'));
        if (empty($entryIds)) {
            return 0;
        }

        $entries = $plan->entries()
            ->whereIn('id', $entryIds)
            ->whereNotNull('recipe_id')
            ->with('recipe')
            ->get()
            ->keyBy('id');

        foreach ($selections as $selection) {
            $entry = $entries->get($selection['entry_id'] ?? null);
            if (! $entry || ! $entry->recipe) {
                continue;
            }

            $ingredientIds = $selection['ingredient_ids'] ?? null;
            // Skip if explicitly empty (user deselected everything for this entry).
            if (is_array($ingredientIds) && empty($ingredientIds)) {
                continue;
            }

            $items = $this->shoppingListService->addRecipeIngredients(
                $list,
                $entry->recipe,
                $user,
                $entry->id,
                $entry->date->toDateString(),
                $ingredientIds,
            );
            $added += $items->count();
        }

        return $added;
    }

    /**
     * Force full regeneration of the shopping list from all recipe entries.
     */
    public function generateShoppingList(MealPlan $plan, User $user): void
    {
        $list = $this->ensureShoppingList($plan, $user);

        // Remove all meal-plan-sourced items
        $plan->entries()->whereNotNull('recipe_id')->get()->each(function ($entry) use ($list) {
            $this->shoppingListService->removeRecipeItems($list, $entry->id);
        });

        // Re-add all recipe ingredients
        $plan->entries()->whereNotNull('recipe_id')->with('recipe')->get()->each(function ($entry) use ($list, $user) {
            $this->shoppingListService->addRecipeIngredients(
                $list,
                $entry->recipe,
                $user,
                $entry->id,
                $entry->date->toDateString()
            );
        });
    }

    /**
     * Ensure the plan has a linked shopping list, creating one if needed.
     */
    private function ensureShoppingList(MealPlan $plan, User $user): ShoppingList
    {
        if ($plan->shopping_list_id && $plan->shoppingList) {
            return $plan->shoppingList;
        }

        $weekLabel = Carbon::parse($plan->week_start)->format('M j').' Groceries';
        $list = $this->shoppingListService->createList($plan->family, $user, $weekLabel);

        $plan->update(['shopping_list_id' => $list->id]);
        $plan->setRelation('shoppingList', $list);

        return $list;
    }

    /**
     * Create cook tasks for all assigned cooks on an entry.
     */
    private function createCookTasks(MealPlanEntry $entry, MealPlan $plan, User $user): void
    {
        foreach ($entry->assigned_cooks as $cookId) {
            $this->createSingleCookTask($entry, $plan, $user, $cookId);
        }
    }

    /**
     * Create a single cook task for one user.
     */
    private function createSingleCookTask(MealPlanEntry $entry, MealPlan $plan, User $user, string $cookId): void
    {
        $cookPoints = $plan->family->settings['cook_task_points'] ?? 5;

        Task::create([
            'family_id' => $plan->family_id,
            'created_by' => $user->id,
            'assigned_to' => $cookId,
            'title' => $this->getCookTaskTitle($entry),
            'due_date' => $entry->date,
            'points' => $cookPoints,
            'is_family_task' => false,
            'source_type' => MealPlanEntry::class,
            'source_id' => $entry->id,
        ]);
    }

    /**
     * Generate a task title based on the entry type.
     */
    private function getCookTaskTitle(MealPlanEntry $entry): string
    {
        if ($entry->recipe_id) {
            return 'Cook: '.($entry->recipe?->title ?? 'Unknown Recipe');
        }
        if ($entry->restaurant_id) {
            return 'Order: '.($entry->restaurant?->name ?? 'Unknown Restaurant');
        }
        if ($entry->meal_preset_id) {
            return 'Dinner: '.($entry->preset?->label ?? 'Unknown Preset');
        }

        return 'Prep: '.($entry->custom_title ?? 'Untitled');
    }
}
