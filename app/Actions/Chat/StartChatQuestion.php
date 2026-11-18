<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Actions\Uplink\SendUplinkCommand;
use App\Jobs\Chat\AnswerChatQuestion;
use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores a question asked in game, links what the player pointed at to stored zones (or to
 * transient zones for areas the site has no data on), asks the mod to sample them once and
 * queues the answer. Idempotent on the message id: a resent ask changes nothing.
 */
final class StartChatQuestion
{
    public const string KIND_SELECTION = 'selection';

    public const string KIND_ZONE = 'zone';

    public const string KIND_BOX = 'box';

    public const string KIND_ROOM = 'room';

    public const string KIND_OBJECT = 'object';

    /** @var list<string> */
    public const array KINDS = [self::KIND_SELECTION, self::KIND_ZONE, self::KIND_BOX, self::KIND_ROOM, self::KIND_OBJECT];

    public const int CONTEXT_TTL_SECONDS = 30;

    public function __construct(private readonly SendUplinkCommand $sendCommand) {}

    /**
     * @param  array{id: string, colonyId: string, conversationId: string, question: string, worldId: int, items: list<array<string, mixed>>}  $message
     */
    public function handle(array $message): ChatQuestion
    {
        $colonyGuid = $message['colonyId'];
        $worldId = $message['worldId'];

        /** @var list<array{zoneId: string, worldId: int, cells: list<int>, capture: bool}> $contextRequests */
        $contextRequests = [];

        $question = DB::transaction(function () use ($message, $colonyGuid, $worldId, &$contextRequests): ChatQuestion {
            $conversation = ChatConversation::query()->firstOrCreate(
                ['id' => $message['conversationId']],
                ['colony_guid' => $colonyGuid],
            );

            $question = ChatQuestion::query()->firstOrCreate(
                ['id' => $message['id']],
                ['chat_conversation_id' => $conversation->id, 'question' => $message['question'], 'status' => ChatQuestion::PENDING],
            );

            if (! $question->wasRecentlyCreated) {
                return $question;
            }

            foreach ($message['items'] as $item) {
                $zone = $this->resolveZone($colonyGuid, $worldId, $item);
                $capture = $item['capture'];

                $question->contextItems()->create([
                    'kind' => $item['kind'],
                    'label' => $item['label'],
                    'zone_id' => $zone?->id,
                    'instance_id' => isset($item['instanceId']) ? (int) $item['instanceId'] : null,
                    'layer_hint' => $item['layerHint'] ?? null,
                    'wants_capture' => $capture,
                ]);

                if ($zone !== null && ($zone->transient || $capture)) {
                    $contextRequests[] = [
                        'zoneId' => $zone->zone_guid,
                        'worldId' => $zone->world_id,
                        'cells' => $zone->transient ? array_values($item['cells'] ?? []) : [],
                        'capture' => $capture,
                    ];
                }
            }

            return $question;
        });

        if (! $question->wasRecentlyCreated) {
            return $question;
        }

        if ($contextRequests !== []) {
            $command = $this->sendCommand->handle($colonyGuid, 'chat.context', [
                'questionId' => $question->id,
                'items' => $contextRequests,
            ], self::CONTEXT_TTL_SECONDS);

            $question->update(['context_requested_at' => now(), 'context_command_id' => $command->id]);
        }

        AnswerChatQuestion::dispatch($question->id);

        return $question;
    }

    /**
     * The stored zone the item points at, or a new transient zone for the cells it covers.
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveZone(string $colonyGuid, int $worldId, array $item): ?Zone
    {
        $zoneGuid = $item['zoneId'] ?? null;
        $usesStoredZone = in_array($item['kind'], [self::KIND_ZONE, self::KIND_SELECTION, self::KIND_OBJECT], true);

        if ($usesStoredZone && is_string($zoneGuid)) {
            $stored = Zone::query()->active()->where('colony_guid', $colonyGuid)->where('zone_guid', $zoneGuid)->first();

            if ($stored !== null) {
                return $stored;
            }
        }

        $cells = array_values($item['cells'] ?? []);

        if ($item['kind'] === self::KIND_ZONE || $cells === []) {
            return null;
        }

        return Zone::query()->create([
            'colony_guid' => $colonyGuid,
            'zone_guid' => str_replace('-', '', (string) Str::uuid()),
            'name' => $item['label'],
            'world_id' => $worldId,
            'version' => 1,
            'cell_count' => count($cells),
            'transient' => true,
        ]);
    }
}
