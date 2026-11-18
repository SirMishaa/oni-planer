<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An in-game chat thread of a colony. The id comes from the mod; `claude_session_id`
 * is the Claude Code session reused to resume the thread.
 */
#[Fillable(['id', 'colony_guid', 'claude_session_id'])]
final class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory;

    use HasUuids;

    /** @return HasMany<ChatQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ChatQuestion::class);
    }
}
