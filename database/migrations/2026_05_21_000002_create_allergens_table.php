<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allergens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_big_nine')->default(false);
            $table->timestamps();

            $table->unique(['family_id', 'slug']);
            $table->index('family_id');
        });

        // Seed the Big 9 as global rows (family_id = null).
        $now = now();
        $bigNine = [
            ['slug' => 'milk',       'name' => 'Milk'],
            ['slug' => 'eggs',       'name' => 'Eggs'],
            ['slug' => 'fish',       'name' => 'Fish'],
            ['slug' => 'shellfish',  'name' => 'Shellfish'],
            ['slug' => 'tree-nuts',  'name' => 'Tree nuts'],
            ['slug' => 'peanuts',    'name' => 'Peanuts'],
            ['slug' => 'wheat',      'name' => 'Wheat / gluten'],
            ['slug' => 'soy',        'name' => 'Soy'],
            ['slug' => 'sesame',     'name' => 'Sesame'],
        ];

        DB::table('allergens')->insert(array_map(fn ($row) => [
            'id' => (string) Str::uuid(),
            'family_id' => null,
            'name' => $row['name'],
            'slug' => $row['slug'],
            'is_big_nine' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $bigNine));
    }

    public function down(): void
    {
        Schema::dropIfExists('allergens');
    }
};
