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
        Schema::create('critter_morphs', function (Blueprint $table): void {
            $table->id();
            $table->string('morph_id')->unique();
            $table->foreignId('critter_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_base')->default(false);
            $table->float('min_temp');
            $table->float('max_temp');
            $table->float('calories_per_cycle');
            $table->float('incubation_time');
            $table->float('lifespan');
            $table->integer('overcrowding_threshold')->default(0);
            $table->json('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('critter_morphs');
    }
};
