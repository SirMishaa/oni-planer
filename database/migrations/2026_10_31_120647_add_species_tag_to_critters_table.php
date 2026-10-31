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
        Schema::table('critters', function (Blueprint $table): void {
            $table->string('species_tag')->nullable()->after('variant_family');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('critters', function (Blueprint $table): void {
            $table->dropColumn('species_tag');
        });
    }
};
