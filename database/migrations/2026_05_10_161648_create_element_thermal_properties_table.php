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
        Schema::create('element_thermal_properties', function (Blueprint $table): void {
            $table->string('element_id')->primary();
            $table->foreign('element_id')->references('element_id')->on('elements')->cascadeOnDelete();
            $table->float('specific_heat_capacity');
            $table->float('thermal_conductivity');
            $table->float('low_temp')->nullable();
            $table->float('high_temp')->nullable();
            $table->float('default_temperature');
            $table->float('light_absorption_factor');
            $table->float('radiation_absorption_factor');
            $table->float('radiation_per_1000_mass');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('element_thermal_properties');
    }
};
