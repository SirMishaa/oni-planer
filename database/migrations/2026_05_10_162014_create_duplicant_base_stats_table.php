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
        Schema::create('duplicant_base_stats', function (Blueprint $table): void {
            $table->id();
            $table->float('oxygen_consumption_gs');
            $table->float('co2_production_gs');
            $table->integer('calories_per_cycle');
            $table->float('mass_kg');
            $table->float('bladder_fill_per_cycle');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duplicant_base_stats');
    }
};
