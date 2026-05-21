<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AllergenSource;
use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\Recipe;
use App\Models\RecipeAllergen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecipeAllergenController extends Controller
{
    /**
     * Confirm or edit a single (recipe, allergen) row. Used by the recipe detail
     * view to confirm an AI-suggested tag, change presence, or remove an entry.
     * In PR 2 most edits go through the recipe form; this endpoint is the
     * surface PR 4 will use for one-click AI confirmation.
     */
    public function update(Request $request, Recipe $recipe, RecipeAllergen $allergen): JsonResponse
    {
        $this->authorize('update', $recipe);

        if ($allergen->recipe_id !== $recipe->id) {
            abort(404);
        }

        $validated = $request->validate([
            'presence' => ['sometimes', 'in:contains,may_contain'],
            'action' => ['sometimes', 'in:confirm,remove'],
        ]);

        if (($validated['action'] ?? null) === 'remove') {
            $allergen->delete();

            return response()->json(['removed' => true]);
        }

        $update = ['source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now()];

        if (isset($validated['presence'])) {
            $update['presence'] = $validated['presence'];
        }

        $allergen->update($update);

        return response()->json(['allergen' => $allergen->fresh()]);
    }

    /**
     * Add a single (recipe, allergen) row, used when the user wants to tag a
     * recipe outside the full form (e.g., from the detail view's "Add allergen"
     * action).
     */
    public function store(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        $validated = $request->validate([
            'allergen_id' => ['required', 'uuid'],
            'presence' => ['required', 'in:contains,may_contain'],
        ]);

        $allergen = Allergen::availableToFamily($recipe->family_id)
            ->where('id', $validated['allergen_id'])
            ->firstOrFail();

        $row = RecipeAllergen::firstOrCreate([
            'recipe_id' => $recipe->id,
            'allergen_id' => $allergen->id,
            'presence' => $validated['presence'],
        ], [
            'id' => (string) Str::uuid(),
            'source' => AllergenSource::HumanConfirmed->value,
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        return response()->json(['allergen' => $row], 201);
    }
}
