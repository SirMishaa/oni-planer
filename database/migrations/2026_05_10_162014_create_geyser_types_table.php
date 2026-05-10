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
        Schema::create('geyser_types', function (Blueprint $table): void {
            $table->id();
            $table->string('geyser_id')->unique();
            $table->enum('type', ['geyser', 'vent', 'volcano', 'fissure']);
            $table->string('element_id');
            $table->foreign('element_id')->references('element_id')->on('elements');
            $table->float('temperature');
            $table->float('max_pressure');
            $table->float('min_yield_rate');
            $table->float('max_yield_rate');
            $table->float('min_eruption_duration');
            $table->float('max_eruption_duration');
            $table->float('min_eruption_period');
            $table->float('max_eruption_period');
            $table->float('dormancy_min_cycles');
            $table->float('dormancy_max_cycles');
            $table->json('name');
            $table->string('dlc_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geyser_types');
    }
};
