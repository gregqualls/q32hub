<?php

namespace App\Models;

use App\Enums\AllergenPresence;
use App\Enums\AllergenSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RecipeAllergen extends Pivot
{
    use HasUuids;

    protected $table = 'recipe_allergens';

    protected $fillable = [
        'recipe_id',
        'allergen_id',
        'presence',
        'source',
        'confidence',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'presence' => AllergenPresence::class,
        'source' => AllergenSource::class,
        'confidence' => 'decimal:3',
        'confirmed_at' => 'datetime',
    ];
}
