<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One proposed variant per zone and its ordered changes. User data: never truncated by `game:import`.
     */
    public function up(): void
    {
        Schema::create('zone_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('zone_plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('zone_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('kind');
            $table->string('state')->default('pending');
            $table->string('prefab_id')->nullable();
            $table->integer('anchor_cell')->nullable();
            $table->string('orientation')->default('Neutral');
            $table->string('object_layer')->nullable();
            $table->boolean('checked');
            $table->string('target_instance_id')->nullable();
            $table->integer('target_cell')->nullable();
            $table->text('note');
            $table->boolean('moved_by_player')->default(false);
            $table->timestamps();

            $table->index(['zone_plan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_plan_changes');
        Schema::dropIfExists('zone_plans');
    }
};
