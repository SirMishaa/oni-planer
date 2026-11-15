<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a critter eats a diet line, from the export's `foodType`: an `item` it picks up (kg you can stock), a
 * `plant` it eats in place, or `prey` it hunts. Only item lines have an amount in kg.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('critter_morph_diets', function (Blueprint $table): void {
            $table->string('food_kind')->default('item')->after('conversion_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('critter_morph_diets', function (Blueprint $table): void {
            $table->dropColumn('food_kind');
        });
    }
};
