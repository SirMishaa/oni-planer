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
        Schema::create('critter_morph_diets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('critter_morph_id')->constrained()->cascadeOnDelete();
            $table->string('consumed_element_id');
            $table->foreign('consumed_element_id')->references('element_id')->on('elements');
            $table->float('amount_per_cycle');
            $table->string('produced_element_id')->nullable();
            $table->foreign('produced_element_id')->references('element_id')->on('elements')->nullOnDelete();
            $table->float('conversion_ratio')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('critter_morph_diets');
    }
};
