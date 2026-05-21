<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AllergenController extends Controller
{
    /**
     * List allergens available to the current family (global Big 9 + family customs).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->currentFamily()->firstOrFail();

        $allergens = Allergen::availableToFamily($family->id)
            ->orderByDesc('is_big_nine')
            ->orderBy('name')
            ->get(['id', 'family_id', 'name', 'slug', 'is_big_nine']);

        return response()->json(['allergens' => $allergens]);
    }

    /**
     * Add a family-scoped custom allergen (parent only).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Allergen::class);

        $user = $request->user();
        $family = $user->currentFamily()->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $slug = Str::slug($validated['name']);

        if ($slug === '') {
            return response()->json(['message' => 'Name must contain at least one alphanumeric character.'], 422);
        }

        $duplicate = Allergen::availableToFamily($family->id)->where('slug', $slug)->exists();
        if ($duplicate) {
            return response()->json(['message' => 'An allergen with that name already exists.'], 422);
        }

        $allergen = Allergen::create([
            'family_id' => $family->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'is_big_nine' => false,
        ]);

        return response()->json(['allergen' => $allergen->only(['id', 'family_id', 'name', 'slug', 'is_big_nine'])], 201);
    }

    /**
     * Rename a family-scoped custom allergen (parent only). Big 9 are immutable.
     */
    public function update(Request $request, Allergen $allergen): JsonResponse
    {
        $this->authorize('update', $allergen);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $slug = Str::slug($validated['name']);

        if ($slug === '') {
            return response()->json(['message' => 'Name must contain at least one alphanumeric character.'], 422);
        }

        $duplicate = Allergen::availableToFamily((string) $allergen->family_id)
            ->where('slug', $slug)
            ->where('id', '!=', $allergen->id)
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'An allergen with that name already exists.'], 422);
        }

        $allergen->update([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        return response()->json(['allergen' => $allergen->only(['id', 'family_id', 'name', 'slug', 'is_big_nine'])]);
    }

    /**
     * Delete a family-scoped custom allergen (parent only). Big 9 are immutable.
     */
    public function destroy(Allergen $allergen): JsonResponse
    {
        $this->authorize('delete', $allergen);

        $allergen->delete();

        return response()->json(['deleted' => true]);
    }
}
