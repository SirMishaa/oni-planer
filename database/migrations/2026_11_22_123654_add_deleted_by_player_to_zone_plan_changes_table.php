<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A change the player deleted stays stored, left out of the plan, so the assistant sees what
     * the player took out until it writes a new plan.
     */
    public function up(): void
    {
        Schema::table('zone_plan_changes', function (Blueprint $table): void {
            $table->boolean('deleted_by_player')->default(false)->after('moved_by_player');
        });
    }

    public function down(): void
    {
        Schema::table('zone_plan_changes', function (Blueprint $table): void {
            $table->dropColumn('deleted_by_player');
        });
    }
};
