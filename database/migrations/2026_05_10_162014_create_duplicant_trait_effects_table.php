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
        Schema::create('duplicant_trait_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duplicant_trait_id')->constrained()->cascadeOnDelete();
            $table->string('stat');
            $table->float('modifier');
            $table->enum('modifier_type', ['multiply', 'add']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duplicant_trait_effects');
    }
};
