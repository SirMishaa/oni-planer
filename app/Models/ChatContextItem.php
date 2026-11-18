<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChatContextItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the player pointed at when asking (`selection|zone|box|room|object`). `zone_id`
 * is the stored zone or the transient zone created for the question; `layer_hint` is a hint
 * for the prompt, never a data filter.
 */
#[Fillable(['chat_question_id', 'kind', 'label', 'zone_id', 'instance_id', 'layer_hint', 'wants_capture', 'refused_reason'])]
final class ChatContextItem extends Model
{
    /** @use HasFactory<ChatContextItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'wants_capture' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChatQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ChatQuestion::class, 'chat_question_id');
    }

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
