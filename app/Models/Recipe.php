<?php

namespace App\Models;

use App\Enums\RecipeSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'family_id',
        'created_by',
        'title',
        'description',
        'servings',
        'prep_time_minutes',
        'cook_time_minutes',
        'total_time_minutes',
        'source_url',
        'source_type',
        'image_path',
        'instructions',
        'notes',
        'is_favorite',
        'sort_order',
        'share_token',
        'share_visible_attribution',
    ];

    protected $casts = [
        'instructions' => 'array',
        'source_type' => RecipeSourceType::class,
        'is_favorite' => 'boolean',
        'servings' => 'integer',
        'prep_time_minutes' => 'integer',
        'cook_time_minutes' => 'integer',
        'total_time_minutes' => 'integer',
        'sort_order' => 'integer',
        'share_visible_attribution' => 'boolean',
    ];

    public function isShared(): bool
    {
        return ! empty($this->share_token);
    }

    public function shareUrl(): ?string
    {
        return $this->isShared() ? url('/r/'.$this->share_token) : null;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('sort_order');
    }

    public function cookLogs(): HasMany
    {
        return $this->hasMany(RecipeCookLog::class)->orderByDesc('cooked_at');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'recipe_tag')
            ->using(RecipeTag::class)
            ->withTimestamps();
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'recipe_allergens')
            ->using(RecipeAllergen::class)
            ->withPivot(['id', 'presence', 'source', 'confidence', 'confirmed_by', 'confirmed_at'])
            ->withTimestamps();
    }

    public function images(): HasMany
    {
        return $this->hasMany(RecipeImage::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Path of the recipe's primary image. Reads from `recipes.image_path`
     * (kept in sync as a denormalized cache) so existing callers don't break.
     * Returns null if the recipe has no images.
     */
    public function primaryImagePath(): ?string
    {
        return $this->image_path ?: null;
    }

    public function scopeForFamily($query, string $familyId): void
    {
        $query->where('family_id', $familyId);
    }

    public function scopeFavorites($query): void
    {
        $query->where('is_favorite', true);
    }

    public function scopeSearch($query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('ingredients', function ($q2) use ($term) {
                    $q2->where('name', 'like', "%{$term}%");
                });
        });
    }

    public function familyAverageRating(): ?float
    {
        return $this->ratings()->avg('score');
    }

    public function userRating(User $user): ?Rating
    {
        return $this->ratings()->where('user_id', $user->id)->first();
    }

    public function totalTime(): ?int
    {
        if ($this->total_time_minutes !== null) {
            return $this->total_time_minutes;
        }

        if ($this->prep_time_minutes !== null && $this->cook_time_minutes !== null) {
            return $this->prep_time_minutes + $this->cook_time_minutes;
        }

        return null;
    }
}
