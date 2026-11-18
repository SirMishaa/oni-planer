<?php

declare(strict_types=1);

namespace App\Listeners\Chat;

use App\Actions\Uplink\SendUplinkCommand;
use App\Events\Uplink\UplinkSessionStarted;
use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Sends the colony's latest conversation when a session starts, so the panel shows it after a reload. */
final readonly class SendChatHistory implements ShouldQueue
{
    public const int TEXT_LIMIT = 4000;

    public const int ENTRY_LIMIT = 20;

    public string $queue;

    public function __construct(private SendUplinkCommand $sendCommand)
    {
        $this->queue = 'chat';
    }

    public function handle(UplinkSessionStarted $event): void
    {
        $conversation = ChatConversation::query()
            ->where('colony_guid', $event->colonyGuid)
            ->has('questions')
            ->latest('updated_at')
            ->latest('created_at')
            ->first();

        if ($conversation === null) {
            return;
        }

        $questions = $conversation->questions()
            ->with('contextItems')
            ->latest('created_at')
            ->limit(self::ENTRY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $this->sendCommand->handle($event->colonyGuid, 'chat.history', [
            'conversationId' => $conversation->id,
            'entries' => $questions->map(fn (ChatQuestion $question): array => array_filter([
                'questionId' => $question->id,
                'question' => $question->question,
                'items' => $question->contextItems->map(fn ($item): array => ['kind' => $item->kind, 'label' => $item->label])->all(),
                'status' => $question->status,
                'answer' => $question->answer === null ? null : mb_substr($question->answer, 0, self::TEXT_LIMIT),
                'error' => $question->error === null ? null : mb_substr($question->error, 0, self::TEXT_LIMIT),
                'durationMs' => $question->duration_ms,
                'tools' => $question->tools,
            ], fn (mixed $value): bool => $value !== null))->all(),
        ]);
    }
}
