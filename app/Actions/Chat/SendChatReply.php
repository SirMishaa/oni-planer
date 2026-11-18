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
        $this->send($question->conversation->colony_guid, $question->chat_conversation_id, $question->id, $kind, $text, $durationMs, $tools);
    }

    /**
     * Sends a reply for ids the game sent, without a stored question (an ask the site refused).
     *
     * @param  list<string>  $tools
     */
    public function send(string $colonyGuid, string $conversationId, string $questionId, string $kind, string $text, ?int $durationMs = null, array $tools = []): void
    {
        $payload = [
            'conversationId' => $conversationId,
            'questionId' => $questionId,
            'kind' => $kind,
            'text' => $text,
        ];

        if ($durationMs !== null) {
            $payload['durationMs'] = $durationMs;
        }

        if ($tools !== []) {
            $payload['tools'] = array_values($tools);
        }

        $this->sendCommand->handle($colonyGuid, 'chat.reply', $payload, self::TTL_SECONDS);
    }
}
