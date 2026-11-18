<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-game chat conversations: one per colony thread, id given by the mod.
     */
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('colony_guid')->index();
            $table->uuid('claude_session_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
