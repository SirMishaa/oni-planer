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
        Schema::create('rocket_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('module_id')->unique();
            $table->enum('module_type', ['cargo_solid', 'cargo_liquid', 'cargo_gas', 'cargo_bio', 'utility', 'command']);
            $table->float('mass');
            $table->float('capacity');
            $table->float('power_consumption')->nullable();
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
        Schema::dropIfExists('rocket_modules');
    }
};
