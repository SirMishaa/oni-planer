<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Chat\StartChatQuestion;
use App\Enums\UplinkMessageType;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * `client-chat.ask`: a question the player typed in game, with what they pointed at. Always
 * acked: a malformed ask never becomes valid by resending it.
 */
final class ReceiveChatAsk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StartChatQuestion $startQuestion): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ChatAsk);

        if ($message === null || ! is_string($message['id'] ?? null)) {
            return;
        }

        if ($this->isWellFormed($message)) {
            $startQuestion->handle($message);
        } else {
            $reader->drop($message, UplinkMessageType::ChatAsk, 'malformed chat ask');
        }

        $reader->acknowledge($message);
    }

    /** @param  array<string, mixed>  $message */
    private function isWellFormed(array $message): bool
    {
        if (! is_string($message['colonyId'] ?? null)
            || ! is_string($message['conversationId'] ?? null)
            || ! is_string($message['question'] ?? null) || mb_trim($message['question']) === ''
            || ! is_int($message['worldId'] ?? null)
            || ! is_array($message['items'] ?? null) || ! array_is_list($message['items'])) {
            return false;
        }

        foreach ($message['items'] as $item) {
            $wellFormed = is_array($item)
                && in_array($item['kind'] ?? null, StartChatQuestion::KINDS, true)
                && is_string($item['label'] ?? null)
                && is_bool($item['capture'] ?? null)
                && (($item['zoneId'] ?? null) === null || is_string($item['zoneId']))
                && (($item['instanceId'] ?? null) === null || is_int($item['instanceId']) || is_string($item['instanceId']))
                && (($item['layerHint'] ?? null) === null || is_string($item['layerHint']))
                && (($item['cells'] ?? null) === null || (is_array($item['cells']) && array_is_list($item['cells']) && array_all($item['cells'], fn (mixed $cell): bool => is_int($cell))));

            if (! $wellFormed) {
                return false;
            }
        }

        return true;
    }
}
