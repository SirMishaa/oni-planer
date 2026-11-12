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
        Schema::create('uplink_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('colony_guid');
            $table->string('session_id')->unique();
            $table->double('loaded_game_seconds');
            $table->integer('cycle');
            $table->dateTime('started_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uplink_sessions');
    }
};
