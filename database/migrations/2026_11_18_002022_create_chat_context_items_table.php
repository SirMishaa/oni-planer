<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the player pointed at when asking: a selection, a zone, a box, a room or an object.
     */
    public function up(): void
    {
        Schema::create('chat_context_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('chat_question_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('label');
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->string('layer_hint')->nullable();
            $table->boolean('wants_capture')->default(false);
            $table->string('refused_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_context_items');
    }
};
