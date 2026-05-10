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
        Schema::create('element_liquid_properties', function (Blueprint $table): void {
            $table->string('element_id')->primary();
            $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
            $table->float('flow');
            $table->float('liquid_surface_area_multiplier');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('element_liquid_properties');
    }
};
