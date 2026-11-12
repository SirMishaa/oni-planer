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
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->string('colony_guid');
            $table->string('zone_guid');
            $table->string('name');
            $table->integer('world_id');
            $table->integer('version');
            $table->integer('cell_count');
            $table->boolean('orphaned')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->json('layout')->nullable();
            $table->json('slow_state')->nullable();
            $table->json('fast_state')->nullable();
            $table->dateTime('layout_at')->nullable();
            $table->dateTime('slow_state_at')->nullable();
            $table->dateTime('fast_state_at')->nullable();
            $table->timestamps();

            $table->unique(['colony_guid', 'zone_guid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
