<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_allergens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('allergen_id')->constrained()->cascadeOnDelete();
            $table->string('presence', 20);          // AllergenPresence: contains | may_contain
            $table->string('source', 20);            // AllergenSource: ai_auto | ai_suggested | human_confirmed | imported
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'allergen_id', 'presence']);
            $table->index(['recipe_id', 'presence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_allergens');
    }
};
