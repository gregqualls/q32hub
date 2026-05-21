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
        Schema::create('recipe_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('path', 512);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['recipe_id', 'sort_order']);
        });

        // Backfill: every recipe with an image_path becomes a single primary
        // recipe_images row. recipes.image_path stays in place as a denormalized
        // cache of the primary's path; new gallery code is the source of truth.
        DB::table('recipes')
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($recipes) {
                $rows = [];
                $now = now();
                foreach ($recipes as $recipe) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'recipe_id' => $recipe->id,
                        'path' => $recipe->image_path,
                        'sort_order' => 0,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows) {
                    DB::table('recipe_images')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_images');
    }
};
