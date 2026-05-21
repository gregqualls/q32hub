<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            // Unguessable token. Nullable: null = not shared. Unique so revocation
            // (set to null) frees the slot for a future share.
            $table->string('share_token', 32)->nullable()->unique();
            // Off by default: public page reads "Shared via Kinhold" unless owner
            // opts in to surface the family name.
            $table->boolean('share_visible_attribution')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'share_visible_attribution']);
        });
    }
};
