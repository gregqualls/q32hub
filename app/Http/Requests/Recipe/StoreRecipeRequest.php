<?php

namespace App\Http\Requests\Recipe;

use App\Models\Recipe;
use App\Rules\FractionalQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Recipe::class);
    }

    /**
     * Normalize fractional quantity strings (e.g. "1/2", "½") to floats
     * before validation runs, so the `numeric` rule passes.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('ingredients')) {
            $ingredients = collect($this->input('ingredients', []))
                ->map(function (mixed $ing) {
                    if (isset($ing['quantity']) && is_string($ing['quantity']) && $ing['quantity'] !== '') {
                        $float = FractionalQuantity::parseToFloat($ing['quantity']);
                        if ($float !== null) {
                            $ing['quantity'] = $float;
                        }
                    }

                    return $ing;
                })
                ->all();

            $this->merge(['ingredients' => $ingredients]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:100'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'cook_time_minutes' => ['nullable', 'integer', 'min:0'],
            'total_time_minutes' => ['nullable', 'integer', 'min:0'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'source_type' => ['nullable', 'in:manual,url,photo,social_media'],
            'image_path' => ['nullable', 'string', 'max:512'],
            'instructions' => ['nullable', 'array'],
            'instructions.*.step' => ['required_with:instructions', 'integer'],
            'instructions.*.text' => ['required_with:instructions', 'string'],
            'notes' => ['nullable', 'string'],
            'is_favorite' => ['nullable', 'boolean'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*.name' => ['required_with:ingredients', 'string', 'max:255'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'ingredients.*.preparation' => ['nullable', 'string', 'max:255'],
            'ingredients.*.group_name' => ['nullable', 'string', 'max:100'],
            'ingredients.*.is_optional' => ['nullable', 'boolean'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [Rule::exists('tags', 'id')->where('family_id', $this->user()->family_id)],
            'allergens' => ['nullable', 'array'],
            'allergens.*.allergen_id' => ['required_with:allergens', 'uuid', 'exists:allergens,id'],
            'allergens.*.presence' => ['required_with:allergens', 'in:contains,may_contain'],
            'images' => ['nullable', 'array'],
            'images.*.id' => ['nullable', 'uuid'],
            'images.*.path' => ['required_with:images', 'string', 'max:512'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'images.*.is_primary' => ['nullable', 'boolean'],
        ];
    }
}
