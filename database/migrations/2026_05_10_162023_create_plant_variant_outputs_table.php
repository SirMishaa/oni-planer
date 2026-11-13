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
        Schema::create('plant_variant_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plant_variant_id')->constrained()->cascadeOnDelete();
            $table->string('element_id');
            $table->foreign('element_id')->references('element_id')->on('elements');
            $table->float('amount_per_harvest');
            $table->enum('output_type', ['food', 'resource']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plant_variant_outputs');
    }
};
