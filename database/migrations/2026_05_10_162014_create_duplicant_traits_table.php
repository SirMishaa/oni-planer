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
        Schema::create('duplicant_traits', function (Blueprint $table): void {
            $table->id();
            $table->string('trait_id')->unique();
            $table->boolean('is_positive')->default(true);
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
        Schema::dropIfExists('duplicant_traits');
    }
};
