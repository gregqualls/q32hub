<?php

namespace App\Jobs;

use App\Models\Recipe;
use App\Services\RecipeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One-recipe AI allergen backfill. Idempotent: skips recipes that already have
 * any allergen rows unless explicitly forced. Skips families without AI access.
 */
class BackfillRecipeAllergens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipeId,
        public readonly bool $force = false,
    ) {}

    public function handle(RecipeImportService $service): void
    {
        $recipe = Recipe::with(['family', 'ingredients', 'allergens'])->find($this->recipeId);
        if (! $recipe) {
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
