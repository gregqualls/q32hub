<?php

namespace App\Jobs;

use App\Models\Recipe;
use App\Services\RecipeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One-recipe AI allergen backfill. Idempotent: skips recipes that already have
 * any allergen rows unless explicitly forced. Skips families without AI access.
 *
 * Defense in depth: the constructor takes both `recipeId` and `familyId`. The
 * handler asserts the recipe still belongs to the expected family before
 * touching it, so a misconfigured dispatcher can't tag recipe A with family
 * B's custom allergens.
 *
 * Cost control: a per-family Anthropic rate limiter caps how aggressively the
 * queue worker can drain a large backfill batch.
 */
class BackfillRecipeAllergens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipeId,
        public readonly string $familyId,
        public readonly bool $force = false,
    ) {}

    /**
     * Per-family Anthropic rate limit (registered in AppServiceProvider).
     * Keeps a big backfill from blowing through API quotas faster than the
     * Anthropic API will accept.
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('allergen-backfill'))->dontRelease(),
        ];
    }

    public function handle(RecipeImportService $service): void
    {
        $recipe = Recipe::with(['family', 'ingredients', 'allergens'])->find($this->recipeId);
        if (! $recipe) {
            return;
        }

        // Defense in depth: a future caller that hands us a mismatched
        // (recipeId, familyId) pair gets short-circuited here.
        if ((string) $recipe->family_id !== $this->familyId) {
            Log::warning('BackfillRecipeAllergens: family mismatch, skipping', [
                'recipe_id' => $recipe->id,
                'expected_family' => $this->familyId,
                'actual_family' => $recipe->family_id,
            ]);

            return;
        }

        if (! $this->force && $recipe->allergens->isNotEmpty()) {
            return;
        }

        $ingredients = $recipe->ingredients->map(fn ($i) => [
            'name' => $i->name,
            'quantity' => $i->quantity,
            'unit' => $i->unit,
            'preparation' => $i->preparation,
        ])->all();

        if (empty($ingredients)) {
            return;
        }

        try {
            $allergens = $service->extractAllergensFromIngredients($ingredients, $recipe->family);
        } catch (\Throwable $e) {
            Log::warning('BackfillRecipeAllergens: extraction failed', [
                'recipe_id' => $recipe->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($allergens)) {
            return;
        }

        // If forced, wipe existing rows before writing so old AI tags get refreshed.
        if ($this->force) {
            $recipe->allergens()->detach();
        }

        $service->persistAiAllergens($recipe, $allergens);
    }
}
