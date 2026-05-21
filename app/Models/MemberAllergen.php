<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MemberAllergen extends Pivot
{
    use HasUuids;

    protected $table = 'member_allergens';
}
