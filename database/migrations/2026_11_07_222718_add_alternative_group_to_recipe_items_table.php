<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Items of one recipe sharing an `alternative_group` are interchangeable:
     * the fabricator uses any one of them. Plain items keep null.
     */
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('alternative_group')->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->dropColumn('alternative_group');
        });
    }
};
