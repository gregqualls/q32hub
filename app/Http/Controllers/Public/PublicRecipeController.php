<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\RecipeAllergen;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicRecipeController extends Controller
{
    /**
     * Render a publicly-shared recipe. No auth required — the token alone is
     * the access grant. Hard 404 if the token doesn't match a current share
     * (covers both never-shared and revoked-share cases).
     */
    public function show(string $token): View
    {
        // Family is loaded only for `share_visible_attribution`; the Blade view
        // never reads `creator`, so it's deliberately omitted from the with().
        $recipe = Recipe::with([
            'images',
            'ingredients',
            'allergens',
            'family',
        ])
            ->where('share_token', $token)
            ->first();

        if (! $recipe) {
            throw new NotFoundHttpException;
        }

        return view('public.recipe', [
            'recipe' => $recipe,
            'attribution' => $this->resolveAttribution($recipe),
            'allergens' => $this->mapAllergens($recipe),
            'images' => $this->mapImages($recipe),
        ]);
    }

    private function resolveAttribution(Recipe $recipe): array
    {
        if ($recipe->share_visible_attribution && $recipe->family) {
            return [
                'visible' => true,
                'family_name' => $recipe->family->name,
            ];
        }

        return [
            'visible' => false,
            'family_name' => null,
        ];
    }

    /**
     * Normalize allergens into a flat shape the Blade view can render without
     * touching Eloquent relations.
     *
     * @return array<int, array{name: string, slug: string, presence: string, source: string}>
     */
    private function mapAllergens(Recipe $recipe): array
    {
        return $recipe->allergens->map(function ($a) {
            /** @var RecipeAllergen $pivot */
            $pivot = $a->getRelationValue('pivot');

            return [
                'name' => $a->name,
                'slug' => $a->slug,
                'presence' => $pivot->presence->value,
                'source' => $pivot->source->value,
            ];
        })->all();
    }

    /**
     * @return array{primary: ?array{path: string, url: string}, extras: array<int, array{path: string, url: string}>}
     */
    private function mapImages(Recipe $recipe): array
    {
        $list = $recipe->images->map(fn ($img) => [
            'path' => $img->path,
            'url' => $this->resolveUrl($img->path),
            'is_primary' => (bool) $img->is_primary,
        ]);

        $primary = $list->firstWhere('is_primary', true) ?: $list->first();
        $extras = $list->reject(fn ($i) => $primary && $i['path'] === $primary['path'])->values()->all();

        return [
            'primary' => $primary ? ['path' => $primary['path'], 'url' => $primary['url']] : null,
            'extras' => array_map(fn ($i) => ['path' => $i['path'], 'url' => $i['url']], $extras),
        ];
    }

    private function resolveUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/storage/')) {
            return $path;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
