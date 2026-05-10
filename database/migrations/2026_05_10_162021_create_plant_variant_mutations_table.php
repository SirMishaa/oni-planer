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
        Schema::create('plant_variant_mutations', function (Blueprint $table): void {
            $table->foreignId('plant_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plant_mutation_id')->constrained()->cascadeOnDelete();
            $table->primary(['plant_variant_id', 'plant_mutation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plant_variant_mutations');
    }
};
