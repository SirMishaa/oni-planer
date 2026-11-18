<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question asked in game and Claude's answer. The id is the mod's reliable message id,
 * so receiving the same message twice finds the same row.
 */
#[Fillable(['id', 'chat_conversation_id', 'question', 'status', 'answer', 'error', 'duration_ms', 'tools', 'context_requested_at', 'context_command_id', 'answered_at'])]
final class ChatQuestion extends Model
{
    /** @use HasFactory<ChatQuestionFactory> */
    use HasFactory;

    use HasUuids;

    public const string PENDING = 'pending';

    public const string WORKING = 'working';

    public const string ANSWERED = 'answered';

    public const string FAILED = 'failed';

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'tools' => 'array',
            'context_requested_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /** @return HasMany<ChatContextItem, $this> */
    public function contextItems(): HasMany
    {
        return $this->hasMany(ChatContextItem::class);
    }
}
