<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Actions\Chat\SendChatReply;
use App\Actions\Chat\StartChatQuestion;
use App\Enums\UplinkMessageType;
use App\Models\ChatConversation;
use App\Services\Uplink\UplinkMessageReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * `client-chat.ask`: a question the player typed in game, with what they pointed at. Always
 * acked: a malformed ask never becomes valid by resending it. A refused ask whose ids are usable
 * gets an error reply, so the panel does not wait for an answer that will never come.
 */
final class ReceiveChatAsk implements ShouldQueue
{
    use Queueable;

    public const int MAX_QUESTION_LENGTH = 4000;

    public const int MAX_TEXT_LENGTH = 255;

    public const int MAX_ITEMS = 16;

    public const int MAX_CELLS = 5000;

    public const string REFUSED_TEXT = 'The planner refused this question (malformed, or the conversation belongs to another colony).';

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(UplinkMessageReader $reader, StartChatQuestion $startQuestion, SendChatReply $sendReply): void
    {
        $message = $reader->read($this->frame, UplinkMessageType::ChatAsk);

        if ($message === null || ! is_string($message['id'] ?? null)) {
            return;
        }

        $canonical = $this->canonical($message);

        if ($canonical !== null && $this->isWellFormed($canonical) && ! $this->conversationBelongsToOtherColony($canonical)) {
            $startQuestion->handle($canonical);
        } else {
            $reader->drop($message, UplinkMessageType::ChatAsk, 'malformed chat ask');

            if ($canonical !== null && is_string($message['colonyId'] ?? null)) {
                $sendReply->send($message['colonyId'], $message['conversationId'], $message['id'], SendChatReply::KIND_ERROR, self::REFUSED_TEXT);
            }
        }

        $reader->acknowledge($message);
    }

    /**
     * The message with `id` and `conversationId` in the dashed UUID form: the mod sends .NET "N" guids (32 hex chars).
     * Null when either is neither form. The ack keeps the id the mod sent.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function canonical(array $message): ?array
    {
        foreach (['id', 'conversationId'] as $key) {
            $value = $message[$key] ?? null;

            if (is_string($value) && preg_match('/^[0-9a-fA-F]{32}$/', $value) === 1) {
                $value = Str::lower(preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $value) ?? $value);
            }

            if (! is_string($value) || ! Str::isUuid($value)) {
                return null;
            }

            $message[$key] = Str::lower($value);
        }

        return $message;
    }

    /** @param  array<string, mixed>  $message */
    private function isWellFormed(array $message): bool
    {
        if (! is_string($message['colonyId'] ?? null)
            || ! Str::isUuid($message['id'] ?? null)
            || ! Str::isUuid($message['conversationId'] ?? null)
            || ! is_string($message['question'] ?? null) || mb_trim($message['question']) === '' || mb_strlen($message['question']) > self::MAX_QUESTION_LENGTH
            || ! is_int($message['worldId'] ?? null) || $message['worldId'] < 0
            || ! is_array($message['items'] ?? null) || ! array_is_list($message['items']) || count($message['items']) > self::MAX_ITEMS) {
            return false;
        }

        $totalCells = 0;

        foreach ($message['items'] as $item) {
            $wellFormed = is_array($item)
                && in_array($item['kind'] ?? null, StartChatQuestion::KINDS, true)
                && is_string($item['label'] ?? null) && mb_strlen($item['label']) <= self::MAX_TEXT_LENGTH
                && is_bool($item['capture'] ?? null)
                && (($item['zoneId'] ?? null) === null || (is_string($item['zoneId']) && mb_strlen($item['zoneId']) <= self::MAX_TEXT_LENGTH))
                && (($item['instanceId'] ?? null) === null || (is_int($item['instanceId']) && $item['instanceId'] >= 0))
                && (($item['layerHint'] ?? null) === null || (is_string($item['layerHint']) && mb_strlen($item['layerHint']) <= self::MAX_TEXT_LENGTH))
                && (($item['cells'] ?? null) === null || (is_array($item['cells']) && array_is_list($item['cells']) && array_all($item['cells'], fn (mixed $cell): bool => is_int($cell) && $cell >= 0)));

            if (! $wellFormed) {
                return false;
            }

            $totalCells += count($item['cells'] ?? []);
        }

        return $totalCells <= self::MAX_CELLS;
    }

    /** An ask whose conversation id already belongs to another colony must not attach to it. */
    /** @param  array<string, mixed>  $message */
    private function conversationBelongsToOtherColony(array $message): bool
    {
        return ChatConversation::query()
            ->whereKey($message['conversationId'])
            ->where('colony_guid', '!=', $message['colonyId'])
            ->exists();
    }
}
