<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = [
        'buildings',
        'elements',
        'recipes',
        'critters',
        'critter_morphs',
        'plants',
        'plant_variants',
        'geyser_types',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('mod_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('mod_id');
            });
        }
    }
};
