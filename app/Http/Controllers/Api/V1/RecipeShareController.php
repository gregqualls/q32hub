<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecipeShareController extends Controller
{
    /**
     * Publish a recipe to a public URL. Idempotent: re-calling on an already-
     * shared recipe returns the existing URL. Parent-only.
     */
    public function store(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        if (! $recipe->share_token) {
            $recipe->forceFill([
                'share_token' => $this->generateUniqueToken(),
            ])->save();
        }

        if ($request->has('visible_attribution')) {
            $recipe->forceFill([
                'share_visible_attribution' => (bool) $request->boolean('visible_attribution'),
            ])->save();
        }

        return response()->json([
            'share' => [
                'is_shared' => true,
                'url' => $recipe->shareUrl(),
                'visible_attribution' => (bool) $recipe->share_visible_attribution,
            ],
        ], 201);
    }

    /**
     * Toggle attribution on an existing share without rotating the token.
     */
    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        if (! $recipe->isShared()) {
            return response()->json(['message' => 'Recipe is not shared yet.'], 422);
        }

        $request->validate([
            'visible_attribution' => ['required', 'boolean'],
        ]);

        $recipe->forceFill([
            'share_visible_attribution' => (bool) $request->boolean('visible_attribution'),
        ])->save();

        return response()->json([
            'share' => [
                'is_shared' => true,
                'url' => $recipe->shareUrl(),
                'visible_attribution' => (bool) $recipe->share_visible_attribution,
            ],
        ]);
    }

    /**
     * Revoke a share. Clears the token so the old public URL hard-404s.
     * Re-sharing later mints a new token (intentional — old links should die
     * permanently on revoke).
     */
    public function destroy(Recipe $recipe): JsonResponse
    {
        $this->authorize('update', $recipe);

        $recipe->forceFill([
            'share_token' => null,
            'share_visible_attribution' => false,
        ])->save();

        return response()->json([
            'share' => [
                'is_shared' => false,
                'url' => null,
                'visible_attribution' => false,
            ],
        ]);
    }

    /**
     * 22-char base62 (~131 bits of entropy). Loops on the off-chance of
     * collision so the unique constraint stays a backstop, not a hot path.
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(22);
        } while (Recipe::where('share_token', $token)->exists());

        return $token;
    }
}
