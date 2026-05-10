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
        Schema::create('elements', function (Blueprint $table): void {
            $table->id();
            $table->string('element_id')->unique();
            $table->enum('state', ['gas', 'liquid', 'solid', 'special']);
            $table->float('molar_mass');
            $table->float('toxicity');
            $table->string('material_category');
            $table->json('tags');
            $table->string('low_temp_transition_target')->nullable();
            $table->string('high_temp_transition_target')->nullable();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('dlc_id')->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('elements');
    }
};
