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
        Schema::create('rocket_engines', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_id')->unique();
            $table->string('fuel_element_id')->nullable();
            $table->foreign('fuel_element_id')->references('element_id')->on('elements')->nullOnDelete();
            $table->string('oxidizer_element_id')->nullable();
            $table->foreign('oxidizer_element_id')->references('element_id')->on('elements')->nullOnDelete();
            $table->float('max_range');
            $table->float('fuel_consumption_rate');
            $table->float('oxidizer_consumption_rate')->nullable();
            $table->float('exhaust_temperature');
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
        Schema::dropIfExists('rocket_engines');
    }
};
