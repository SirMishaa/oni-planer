<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordering of latest-only states: the session and game time of the stored
     * state, so a late or older-session state never replaces a newer one.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table): void {
            $table->string('slow_session_id')->nullable()->after('slow_state_at');
            $table->double('slow_game_seconds')->nullable()->after('slow_session_id');
            $table->string('fast_session_id')->nullable()->after('fast_state_at');
            $table->double('fast_game_seconds')->nullable()->after('fast_session_id');
        });

        Schema::table('colonies', function (Blueprint $table): void {
            $table->string('session_id')->nullable();
            $table->double('game_seconds')->nullable();
        });

        Schema::table('uplink_sessions', function (Blueprint $table): void {
            $table->index(['colony_guid', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('uplink_sessions', function (Blueprint $table): void {
            $table->dropIndex(['colony_guid', 'started_at']);
        });

        Schema::table('colonies', function (Blueprint $table): void {
            $table->dropColumn(['session_id', 'game_seconds']);
        });

        Schema::table('zones', function (Blueprint $table): void {
            $table->dropColumn(['slow_session_id', 'slow_game_seconds', 'fast_session_id', 'fast_game_seconds']);
        });
    }
};
