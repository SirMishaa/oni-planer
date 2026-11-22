<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('critter_morphs_and_plant_variants', function (Blueprint $table) {
            foreach (['buildings', 'critter_morphs', 'critter_morph_diets', 'plant_variants'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->json('codex_data')->nullable();
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('critter_morphs_and_plant_variants', function (Blueprint $table) {
            foreach (['buildings', 'critter_morphs', 'critter_morph_diets', 'plant_variants'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('codex_data');
                });
            }
        });
    }
};
