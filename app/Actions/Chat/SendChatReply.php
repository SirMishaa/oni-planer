<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Actions\Uplink\SendUplinkCommand;
use App\Models\ChatQuestion;

/** Sends a `chat.reply` command (working, answer or error) for a question back to the game. */
final class SendChatReply
{
    public const string KIND_WORKING = 'working';

    public const string KIND_ANSWER = 'answer';

    public const string KIND_ERROR = 'error';

    public const int TTL_SECONDS = 120;

    public function __construct(private readonly SendUplinkCommand $sendCommand) {}

    /**
     * @param  list<string>  $tools
     */
    public function handle(ChatQuestion $question, string $kind, string $text, ?int $durationMs = null, array $tools = []): void
    {
        $payload = [
            'conversationId' => $question->chat_conversation_id,
            'questionId' => $question->id,
            'kind' => $kind,
            'text' => $text,
        ];

        if ($durationMs !== null) {
            $payload['durationMs'] = $durationMs;
        }

        if ($tools !== []) {
            $payload['tools'] = array_values($tools);
        }

        $this->sendCommand->handle($question->conversation->colony_guid, 'chat.reply', $payload, self::TTL_SECONDS);
    }
}
