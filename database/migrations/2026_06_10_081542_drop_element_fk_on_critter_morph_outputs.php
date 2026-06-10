<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Critter outputs include egg prefab ids (e.g. "HatchEgg") which are not elements,
 * so element_id cannot reference the elements table. Drop the foreign key while
 * keeping the column (indexed) so egg/dropping/resource outputs can be stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('critter_morph_outputs', function (Blueprint $table): void {
            $table->dropForeign(['element_id']);
            $table->index('element_id');
        });
    }

    public function down(): void
    {
        Schema::table('critter_morph_outputs', function (Blueprint $table): void {
            $table->dropIndex(['element_id']);
            $table->foreign('element_id')->references('element_id')->on('elements');
        });
    }
};
