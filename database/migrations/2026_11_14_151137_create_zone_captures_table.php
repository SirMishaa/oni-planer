<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_captures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->timestamp('taken_at');
            $table->double('game_seconds');
            $table->string('layout_signature')->nullable();
            $table->string('trigger');
            $table->uuid('command_id')->nullable()->index();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            $table->index(['zone_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_captures');
    }
};
