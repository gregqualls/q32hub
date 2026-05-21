<?php

namespace App\Services;

use App\Enums\AllergenSource;
use App\Models\Allergen;
use App\Models\Family;
use App\Models\Rating;
use App\Models\Recipe;
use App\Models\RecipeCookLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipeService
{
    public function createRecipe(Family $family, User $user, array $data): Recipe
    {
        $recipe = Recipe::create([
            'family_id' => $family->id,
            'created_by' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'servings' => $data['servings'] ?? 4,
            'prep_time_minutes' => $data['prep_time_minutes'] ?? null,
            'cook_time_minutes' => $data['cook_time_minutes'] ?? null,
            'total_time_minutes' => $data['total_time_minutes'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'source_type' => $data['source_type'] ?? 'manual',
            'image_path' => $data['image_path'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_favorite' => $data['is_favorite'] ?? false,
        ]);

        if (! empty($data['ingredients'])) {
            $this->insertIngredients($recipe, $data['ingredients']);
        }

        if (isset($data['tag_ids'])) {
            $recipe->tags()->sync($data['tag_ids']);
        }

        if (array_key_exists('allergens', $data)) {
            $this->syncAllergens($recipe, $user, $data['allergens'] ?? []);
        }

        if (array_key_exists('images', $data)) {
            $this->syncImages($recipe, $data['images'] ?? []);
        } elseif (! empty($data['image_path'])) {
            // Backwards-compat: a single image_path becomes the primary image.
            $this->syncImages($recipe, [['path' => $data['image_path'], 'is_primary' => true]]);
        }

        return $recipe->load(['ingredients', 'tags', 'allergens', 'images', 'creator']);
    }

    public function updateRecipe(Recipe $recipe, array $data): Recipe
    {
        $fields = [
            'title', 'description', 'servings', 'prep_time_minutes', 'cook_time_minutes',
            'total_time_minutes', 'source_url', 'source_type', 'image_path', 'instructions',
            'notes', 'is_favorite',
        ];

        $updateData = array_intersect_key($data, array_flip($fields));

        if (! empty($updateData)) {
            $recipe->update($updateData);
        }

        if (array_key_exists('ingredients', $data)) {
            $recipe->ingredients()->delete();
            if (! empty($data['ingredients'])) {
                $this->insertIngredients($recipe, $data['ingredients']);
            }
        }

        if (array_key_exists('tag_ids', $data)) {
            $recipe->tags()->sync($data['tag_ids']);
        }

        if (array_key_exists('allergens', $data)) {
            $editor = $recipe->creator()->first() ?? auth()->user();
            $this->syncAllergens($recipe, $editor, $data['allergens'] ?? []);
        }

        if (array_key_exists('images', $data)) {
            $this->syncImages($recipe, $data['images'] ?? []);
        }

        return $recipe->load(['ingredients', 'tags', 'allergens', 'images', 'creator']);
    }

    public function deleteRecipe(Recipe $recipe): void
    {
        $recipe->delete();
    }

    public function restoreRecipe(Recipe $recipe): void
    {
        $recipe->restore();
    }

    public function toggleFavorite(Recipe $recipe): Recipe
    {
        $recipe->is_favorite = ! $recipe->is_favorite;
        $recipe->save();

        return $recipe;
    }

    public function addCookLog(Recipe $recipe, User $user, array $data): RecipeCookLog
    {
        return RecipeCookLog::create([
            'recipe_id' => $recipe->id,
            'user_id' => $user->id,
            'cooked_at' => $data['cooked_at'],
            'servings_made' => $data['servings_made'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function rateRecipe(Recipe $recipe, User $user, int $score): Rating
    {
        return Rating::updateOrCreate(
            [
                'user_id' => $user->id,
                'rateable_type' => Recipe::class,
                'rateable_id' => $recipe->id,
            ],
            [
                'score' => $score,
                'family_id' => $user->family_id,
            ]
        );
    }

    public function searchRecipes(Family $family, array $filters): LengthAwarePaginator
    {
        $query = Recipe::forFamily((string) $family->id);

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $filters['tag']));
        }

        if (! empty($filters['favorite'])) {
            $query->favorites();
        }

        // Allergen filtering: exclude recipes carrying any allergen for the given
        // reviewed-profile members. `safe_for=all` resolves to every family member
        // with a reviewed profile (skipping unreviewed to avoid false-safe filtering).
        $unsafeAllergenIds = $this->unsafeAllergenIdsForFilter($family, $filters);
        if ($unsafeAllergenIds !== null && $unsafeAllergenIds->isNotEmpty()) {
            $query->whereDoesntHave(
                'allergens',
                fn ($q) => $q->whereIn('allergens.id', $unsafeAllergenIds)
            );
        }

        $sort = $filters['sort'] ?? 'recent';
        match ($sort) {
            'alpha' => $query->orderBy('title'),
            'rating' => $query->orderByDesc(
                Rating::selectRaw('AVG(score)')
                    ->whereColumn('rateable_id', 'recipes.id')
                    ->where('rateable_type', Recipe::class)
                    ->limit(1)
            ),
            default => $query->orderByDesc('created_at'),
        };

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->with(['ingredients', 'tags', 'allergens', 'images', 'creator', 'ratings'])->paginate($perPage);
    }

    /**
     * Resolve the set of allergen IDs that should disqualify a recipe given the
     * `safe_for_members` filter. Returns null when no filtering is requested,
     * or a (possibly empty) collection of allergen IDs otherwise.
     *
     * Members without a reviewed allergy profile are skipped, so a recipe is
     * never silently considered "safe for someone we never asked about."
     */
    private function unsafeAllergenIdsForFilter(Family $family, array $filters): ?Collection
    {
        $memberIds = null;

        if (($filters['safe_for'] ?? null) === 'all') {
            $memberIds = User::where('family_id', $family->id)
                ->whereNotNull('allergen_profile_reviewed_at')
                ->pluck('id');
        } elseif (! empty($filters['safe_for_members'])) {
            $memberIds = User::where('family_id', $family->id)
                ->whereIn('id', (array) $filters['safe_for_members'])
                ->whereNotNull('allergen_profile_reviewed_at')
                ->pluck('id');
        }

        if ($memberIds === null) {
            return null;
        }

        if ($memberIds->isEmpty()) {
            return collect();
        }

        return DB::table('member_allergens')
            ->whereIn('user_id', $memberIds)
            ->pluck('allergen_id')
            ->unique()
            ->values();
    }

    /**
     * Sync a recipe's images. The form sends the full ordered list each time;
     * we diff against the existing rows so paths the form still references
     * stay put (with their IDs preserved), new paths get rows, removed paths
     * get deleted. recipes.image_path stays in sync with the primary as a
     * denormalized cache for cards / cross-cutting readers.
     *
     * Accepted entry shapes:
     *   - { id?: string, path: string, sort_order?: int, is_primary?: bool }
     *
     * If no `is_primary` flag is set on any entry, the first one becomes primary.
     */
    private function syncImages(Recipe $recipe, array $images): void
    {
        $existingById = $recipe->images()->get()->keyBy('id');
        $now = now();
        $keptIds = [];
        $primaryPath = null;

        foreach (array_values($images) as $idx => $entry) {
            $path = $entry['path'] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }
            $sortOrder = $entry['sort_order'] ?? $idx;
            $isPrimary = (bool) ($entry['is_primary'] ?? false);
            $existing = isset($entry['id']) ? $existingById->get($entry['id']) : null;

            if ($existing) {
                $existing->fill([
                    'path' => $path,
                    'sort_order' => $sortOrder,
                    'is_primary' => $isPrimary,
                ])->save();
                $keptIds[] = $existing->id;
            } else {
                $row = $recipe->images()->create([
                    'path' => $path,
                    'sort_order' => $sortOrder,
                    'is_primary' => $isPrimary,
                ]);
                $keptIds[] = $row->id;
            }

            if ($isPrimary && ! $primaryPath) {
                $primaryPath = $path;
            }
        }

        // Drop removed rows
        $recipe->images()->whereNotIn('id', $keptIds ?: ['00000000-0000-0000-0000-000000000000'])->delete();

        // If no explicit primary, fall back to the first remaining row
        if (! $primaryPath) {
            $first = $recipe->images()->orderBy('sort_order')->first();
            if ($first) {
                if (! $first->is_primary) {
                    $first->forceFill(['is_primary' => true])->save();
                }
                $primaryPath = $first->path;
            }
        } else {
            // Make sure only one row is marked primary in the DB
            $recipe->images()->where('path', '!=', $primaryPath)->update(['is_primary' => false]);
        }

        // Sync the denormalized cache so cards keep working
        $recipe->forceFill(['image_path' => $primaryPath])->save();

        unset($now);
    }

    /**
     * Replace a recipe's allergen tags. All entries written by this method are
     * `human_confirmed`. AI-driven writes go through the import service in PR 4.
     *
     * Allergens must be available to the recipe's family (global Big 9 or family
     * customs). Unknown allergens are silently skipped (form validation should
     * catch them earlier).
     */
    private function syncAllergens(Recipe $recipe, ?User $editor, array $allergens): void
    {
        $allowed = Allergen::availableToFamily($recipe->family_id)
            ->whereIn('id', collect($allergens)->pluck('allergen_id')->filter()->all())
            ->pluck('id')
            ->flip();

        $now = now();
        $rows = [];
        $seen = [];

        foreach ($allergens as $entry) {
            $allergenId = $entry['allergen_id'] ?? null;
            $presence = $entry['presence'] ?? null;
            if (! $allergenId || ! $presence || ! isset($allowed[$allergenId])) {
                continue;
            }
            $dedupeKey = $allergenId.'|'.$presence;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $rows[$dedupeKey] = [
                'id' => Str::uuid()->toString(),
                'recipe_id' => $recipe->id,
                'allergen_id' => $allergenId,
                'presence' => $presence,
                'source' => AllergenSource::HumanConfirmed->value,
                'confidence' => null,
                'confirmed_by' => $editor?->id,
                'confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Replace strategy: delete current rows, insert new. Future PRs (AI) will
        // be smarter about preserving provenance on unchanged entries.
        $recipe->allergens()->detach();
        if ($rows) {
            DB::table('recipe_allergens')->insert(array_values($rows));
        }
    }

    private function insertIngredients(Recipe $recipe, array $ingredients): void
    {
        $rows = [];
        $seen = [];
        $sortOrder = 0;

        foreach ($ingredients as $ingredient) {
            $name = trim($ingredient['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // Deduplicate by lowercase name + quantity + unit
            $dedupeKey = mb_strtolower($name).'|'.($ingredient['quantity'] ?? '').'|'.($ingredient['unit'] ?? '');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'recipe_id' => $recipe->id,
                'name' => $name,
                'quantity' => $ingredient['quantity'] ?? null,
                'unit' => $ingredient['unit'] ?? null,
                'preparation' => $ingredient['preparation'] ?? null,
                'group_name' => $ingredient['group_name'] ?? null,
                'is_optional' => $ingredient['is_optional'] ?? false,
                'sort_order' => $ingredient['sort_order'] ?? $sortOrder++,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($rows)) {
            $recipe->ingredients()->insert($rows);
        }
    }
}
