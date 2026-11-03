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
        Schema::table('buildings', function (Blueprint $table): void {
            $table->string('subcategory')->nullable()->after('category');
            $table->json('ports')->nullable();
            $table->string('build_location_rule')->nullable();
            $table->string('permitted_rotations')->nullable();
            $table->string('object_layer')->nullable();
            $table->integer('scene_layer')->nullable();
            $table->string('overlay')->nullable();
            $table->boolean('is_foundation')->default(false);
            $table->boolean('is_tile')->default(false);
            $table->boolean('is_utility')->default(false);
            $table->boolean('drag_build')->default(false);
            $table->boolean('replaceable')->default(false);
            $table->json('placement_rect')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table): void {
            $table->dropColumn([
                'subcategory', 'ports', 'build_location_rule', 'permitted_rotations',
                'object_layer', 'scene_layer', 'overlay', 'is_foundation', 'is_tile',
                'is_utility', 'drag_build', 'replaceable', 'placement_rect',
            ]);
        });
    }
};
