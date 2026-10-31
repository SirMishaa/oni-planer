<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diet inputs/outputs are not always elements: critters also eat plants,
     * food items, and other critters (e.g. Blowter eats Lettuce, Rhex eats
     * hatches), so these columns keep prefab/tag ids that have no elements row.
     */
    public function up(): void
    {
        Schema::table('critter_morph_diets', function (Blueprint $table) {
            $table->dropForeign(['consumed_element_id']);
            $table->dropForeign(['produced_element_id']);
        });
    }

    public function down(): void
    {
        Schema::table('critter_morph_diets', function (Blueprint $table) {
            $table->foreign('consumed_element_id')->references('element_id')->on('elements');
            $table->foreign('produced_element_id')->references('element_id')->on('elements')->nullOnDelete();
        });
    }
};
