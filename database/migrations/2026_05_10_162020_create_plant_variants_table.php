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
        Schema::create('plant_variants', function (Blueprint $table): void {
            $table->id();
            $table->string('variant_id')->unique();
            $table->foreignId('plant_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_base')->default(false);
            $table->float('min_temp');
            $table->float('max_temp');
            $table->float('min_pressure');
            $table->float('max_pressure');
            $table->string('atmosphere_element_id')->nullable();
            $table->foreign('atmosphere_element_id')->references('element_id')->on('elements')->nullOnDelete();
            $table->boolean('light_required')->default(false);
            $table->float('growth_time');
            $table->json('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plant_variants');
    }
};
