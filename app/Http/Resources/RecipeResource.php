<?php

namespace App\Http\Resources;

use App\Models\Recipe;
use App\Models\RecipeAllergen;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Recipe $recipe */
        $recipe = $this->resource;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'servings' => $this->servings,
            'prep_time_minutes' => $this->prep_time_minutes,
            'cook_time_minutes' => $this->cook_time_minutes,
            'total_time_minutes' => $this->total_time_minutes,
            'source_url' => $this->source_url,
            'source_type' => $this->source_type,
            'image_path' => $this->image_path,
            'share' => [
                'is_shared' => $recipe->isShared(),
                'url' => $recipe->shareUrl(),
                'visible_attribution' => (bool) $recipe->share_visible_attribution,
            ],
            'images' => $this->whenLoaded('images', fn () => $recipe->images->map(fn ($img) => [
                'id' => $img->id,
                'path' => $img->path,
                'sort_order' => $img->sort_order,
                'is_primary' => (bool) $img->is_primary,
            ])->values()),
            'instructions' => $this->instructions,
            'notes' => $this->notes,
            'is_favorite' => $this->is_favorite,
            'family_average_rating' => $recipe->relationLoaded('ratings')
                ? round((float) $recipe->ratings->avg('score'), 1)
                : round((float) $recipe->familyAverageRating(), 1),
            'user_rating' => $recipe->relationLoaded('ratings')
                ? $recipe->ratings->firstWhere('user_id', $request->user()?->id)?->score
                : $recipe->userRating($request->user())?->score,
            'ingredients' => RecipeIngredientResource::collection($this->whenLoaded('ingredients')),
            'cook_logs' => RecipeCookLogResource::collection($this->whenLoaded('cookLogs')),
            'ratings' => RatingResource::collection($this->whenLoaded('ratings')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'allergens' => $this->whenLoaded('allergens', fn () => $recipe->allergens->map(function ($a) {
                /** @var RecipeAllergen $pivot */
                $pivot = $a->getRelationValue('pivot');

                return [
                    'id' => $a->id,
                    'pivot_id' => $pivot->id,
                    'name' => $a->name,
                    'slug' => $a->slug,
                    'is_big_nine' => (bool) $a->is_big_nine,
                    'presence' => $pivot->presence->value,
                    'source' => $pivot->source->value,
                    'confidence' => $pivot->confidence !== null ? (float) $pivot->confidence : null,
                    'confirmed_at' => $pivot->confirmed_at,
                ];
            })->values()),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
