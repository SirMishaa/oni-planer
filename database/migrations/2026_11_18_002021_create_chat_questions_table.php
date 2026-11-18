<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Questions asked in game; the id is the mod's reliable message id, so a resend is idempotent.
     */
    public function up(): void
    {
        Schema::create('chat_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->string('status')->default('pending');
            $table->text('answer')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('tools')->nullable();
            $table->dateTime('context_requested_at')->nullable();
            $table->foreignUuid('context_command_id')->nullable()->constrained('uplink_commands')->nullOnDelete();
            $table->dateTime('answered_at')->nullable();
            $table->timestamps();

            $table->index(['chat_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_questions');
    }
};
