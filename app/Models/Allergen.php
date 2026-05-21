<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Allergen extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'family_id',
        'name',
        'slug',
        'is_big_nine',
    ];

    protected $casts = [
        'is_big_nine' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Allergen $allergen): void {
            if (empty($allergen->slug) && ! empty($allergen->name)) {
                $allergen->slug = Str::slug($allergen->name);
            }
        });
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'member_allergens')->using(MemberAllergen::class)->withTimestamps();
    }

    /**
     * Global Big 9 (family_id is null) plus the given family's customs.
     */
    public function scopeAvailableToFamily($query, string $familyId)
    {
        return $query->where(function ($q) use ($familyId) {
            $q->whereNull('family_id')->orWhere('family_id', $familyId);
        });
    }
}
