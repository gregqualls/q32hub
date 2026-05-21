<?php

namespace App\Http\Requests\MealPlan;

use App\Enums\MealSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMealPlanEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $familyId = $this->user()->family_id;

        return [
            'date' => ['required', 'date'],
            'meal_slot' => ['required', 'string', Rule::enum(MealSlot::class)],
            'recipe_id' => ['nullable', 'uuid', Rule::exists('recipes', 'id')->where('family_id', $familyId)],
            'restaurant_id' => ['nullable', 'uuid', Rule::exists('restaurants', 'id')],
            'meal_preset_id' => ['nullable', 'uuid', Rule::exists('meal_presets', 'id')->where('family_id', $familyId)],
            'custom_title' => ['nullable', 'string', 'max:255'],
            'servings' => ['nullable', 'integer', 'min:1'],
            'assigned_cooks' => ['nullable', 'array'],
            'assigned_cooks.*' => ['uuid', Rule::exists('users', 'id')->where('family_id', $familyId)],
            'notes' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
            'acknowledge_allergens' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Ensure exactly one source field is provided.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sources = array_filter([
                    $this->input('recipe_id'),
                    $this->input('restaurant_id'),
                    $this->input('meal_preset_id'),
                    $this->input('custom_title'),
                ]);

                if (count($sources) !== 1) {
                    $validator->errors()->add(
                        'source',
                        'Exactly one of recipe_id, restaurant_id, meal_preset_id, or custom_title must be provided.'
                    );
                }
            },
        ];
    }
}
