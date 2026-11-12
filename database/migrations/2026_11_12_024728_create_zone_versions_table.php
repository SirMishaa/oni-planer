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
        Schema::create('zone_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->integer('version');
            $table->json('cells');
            $table->double('valid_from_game_seconds');
            $table->timestamps();

            $table->unique(['zone_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zone_versions');
    }
};
