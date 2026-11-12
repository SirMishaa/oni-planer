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
        Schema::table('elements', function (Blueprint $table): void {
            $table->unsignedInteger('render_color')->nullable()->after('ui_color');
            $table->string('substance_texture')->nullable()->after('render_color');
            $table->boolean('uses_caustics')->default(false)->after('substance_texture');
            $table->boolean('glows')->default(false)->after('uses_caustics');
            $table->boolean('is_metallic')->default(false)->after('glows');
            $table->boolean('is_opaque_liquid')->default(false)->after('is_metallic');
            $table->float('liquid_scroll_speed')->nullable()->after('is_opaque_liquid');
            $table->float('max_mass')->nullable()->after('liquid_scroll_speed');
            $table->string('terrain_texture')->nullable()->after('max_mass');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('elements', function (Blueprint $table): void {
            $table->dropColumn([
                'render_color', 'substance_texture', 'uses_caustics', 'glows', 'is_metallic',
                'is_opaque_liquid', 'liquid_scroll_speed', 'max_mass', 'terrain_texture',
            ]);
        });
    }
};
