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
        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->string('building_id')->unique();
            $table->string('category');
            $table->float('power_consumption')->nullable();
            $table->float('power_generation')->nullable();
            $table->float('heat_generation')->default(0);
            $table->integer('width')->default(1);
            $table->integer('height')->default(1);
            $table->float('construction_time')->default(0);
            $table->json('tags');
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('dlc_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
