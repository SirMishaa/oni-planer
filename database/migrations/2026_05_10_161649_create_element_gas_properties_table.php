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
        Schema::create('element_gas_properties', function (Blueprint $table): void {
            $table->string('element_id')->primary();
            $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
            $table->float('flow');
            $table->float('default_pressure');
            $table->float('gas_surface_area_multiplier');
            $table->boolean('is_breathable')->default(false);
            $table->boolean('is_toxic')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('element_gas_properties');
    }
};
