<?php

declare(strict_types=1);

use App\Events\Uplink\UplinkSessionStarted;
use App\Jobs\Chat\AnswerChatQuestion;
use App\Models\ChatContextItem;
use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use App\Models\UplinkCommand;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake([AnswerChatQuestion::class]);
    $this->conversationId = (string) Str::uuid();
    $this->questionId = (string) Str::uuid();
});

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function chatAskMessage(string $id, string $conversationId, array $items, array $override = []): array
{
    return [
        'schema' => 1, 'id' => $id, 'colonyId' => 'colony-1', 'sessionId' => 'session-1',
        'conversationId' => $conversationId, 'question' => 'Why is it hot?', 'worldId' => 0,
        'items' => $items, ...$override,
    ];
}

it('sends the latest conversation when a session starts', function (): void {
    $conversation = ChatConversation::factory()->create(['colony_guid' => 'colony-1']);
    $answered = ChatQuestion::factory()->for($conversation, 'conversation')->create([
        'question' => 'Why?', 'status' => ChatQuestion::ANSWERED, 'answer' => 'Because.', 'duration_ms' => 1200, 'tools' => ['mcp__oni__zone_overview'],
    ]);
    ChatContextItem::factory()->for($answered, 'question')->create(['kind' => 'zone', 'label' => 'Farm']);
    ChatConversation::factory()->create(['colony_guid' => 'other']);

    UplinkSessionStarted::dispatch('colony-1', 'session-1');

    $command = UplinkCommand::query()->sole();
    expect($command->type)->toBe('chat.history')
        ->and($command->colony_guid)->toBe('colony-1')
        ->and($command->payload)->toBe([
            'conversationId' => $conversation->id,
            'entries' => [[
                'questionId' => $answered->id, 'question' => 'Why?', 'items' => [['kind' => 'zone', 'label' => 'Farm']],
                'status' => 'answered', 'answer' => 'Because.', 'durationMs' => 1200, 'tools' => ['mcp__oni__zone_overview'],
            ]],
        ]);
});

it('sends no history when the colony has no conversation', function (): void {
    UplinkSessionStarted::dispatch('colony-1', 'session-1');

    expect(UplinkCommand::query()->count())->toBe(0);
});

it('stores the question and links stored zones', function (): void {
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);
    $message = chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'zone', 'label' => 'Farm', 'zoneId' => 'zone-a', 'capture' => false],
        ['kind' => 'object', 'label' => 'Pump', 'instanceId' => 77, 'cells' => [1, 2], 'zoneId' => 'zone-a', 'layerHint' => 'gas', 'capture' => false],
    ]);

    $acks = receiveUplinkData(uplinkData('client-chat.ask', $message));

    $question = ChatQuestion::query()->sole();
    expect($acks)->toHaveCount(1)
        ->and($question->id)->toBe($this->questionId)
        ->and($question->status)->toBe(ChatQuestion::PENDING)
        ->and($question->conversation->colony_guid)->toBe('colony-1')
        ->and($question->contextItems->pluck('zone_id')->all())->toBe([$zone->id, $zone->id])
        ->and($question->contextItems->last()->instance_id)->toBe(77)
        ->and($question->contextItems->last()->layer_hint)->toBe('gas')
        ->and(Zone::query()->count())->toBe(1);
    Bus::assertDispatched(AnswerChatQuestion::class, fn (AnswerChatQuestion $job): bool => $job->questionId === $this->questionId && $job->queue === 'chat');
});

it('creates transient zones and sends one chat.context command for boxes and rooms', function (): void {
    $message = chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'box', 'label' => 'Box 1', 'cells' => [10, 11, 12], 'capture' => true],
        ['kind' => 'room', 'label' => 'Latrine', 'cells' => [20, 21], 'capture' => false],
    ], ['worldId' => 3]);

    receiveUplinkData(uplinkData('client-chat.ask', $message));

    $zones = Zone::query()->where('transient', true)->orderBy('id')->get();
    $command = UplinkCommand::query()->sole();
    $question = ChatQuestion::query()->sole();

    expect($zones)->toHaveCount(2)
        ->and($zones[0]->zone_guid)->toMatch('/^[0-9a-f]{32}$/')
        ->and([$zones[0]->name, $zones[0]->world_id, $zones[0]->version, $zones[0]->cell_count])->toBe(['Box 1', 3, 1, 3])
        ->and($command->type)->toBe('chat.context')
        ->and($command->expires_at->diffInSeconds($command->issued_at, true))->toBeBetween(29, 31)
        ->and($command->payload)->toBe([
            'questionId' => $this->questionId,
            'items' => [
                ['zoneId' => $zones[0]->zone_guid, 'worldId' => 3, 'cells' => [10, 11, 12], 'capture' => true],
                ['zoneId' => $zones[1]->zone_guid, 'worldId' => 3, 'cells' => [20, 21], 'capture' => false],
            ],
        ])
        ->and($question->context_command_id)->toBe($command->id)
        ->and($question->context_requested_at)->not->toBeNull()
        ->and($question->contextItems->pluck('zone_id')->all())->toBe($zones->pluck('id')->all());
});

it('sends no command when everything is already stored', function (): void {
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    receiveUplinkData(uplinkData('client-chat.ask', chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'zone', 'label' => 'Farm', 'zoneId' => 'zone-a', 'capture' => false],
    ])));

    expect(UplinkCommand::query()->count())->toBe(0)
        ->and(ChatQuestion::query()->sole()->context_command_id)->toBeNull();
    Bus::assertDispatched(AnswerChatQuestion::class);
});

it('asks for a capture of a stored zone without sending its cells', function (): void {
    Zone::factory()->create(['colony_guid' => 'colony-1', 'zone_guid' => 'zone-a']);

    receiveUplinkData(uplinkData('client-chat.ask', chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'zone', 'label' => 'Farm', 'zoneId' => 'zone-a', 'capture' => true],
    ])));

    expect(UplinkCommand::query()->sole()->payload['items'])->toBe([['zoneId' => 'zone-a', 'worldId' => 0, 'cells' => [], 'capture' => true]]);
});

it('stores a resent ask only once', function (): void {
    $frame = uplinkData('client-chat.ask', chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'box', 'label' => 'Box 1', 'cells' => [10], 'capture' => false],
    ]));

    receiveUplinkData($frame);
    $acks = receiveUplinkData($frame);

    expect($acks)->toHaveCount(1)
        ->and(ChatQuestion::query()->count())->toBe(1)
        ->and(Zone::query()->count())->toBe(1)
        ->and(UplinkCommand::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(AnswerChatQuestion::class, 1);
});

it('drops a malformed ask and still acks it', function (): void {
    $acks = receiveUplinkData(uplinkData('client-chat.ask', chatAskMessage($this->questionId, $this->conversationId, [
        ['kind' => 'nonsense', 'label' => 'x', 'capture' => false],
    ])));

    expect($acks)->toHaveCount(1)
        ->and(ChatQuestion::query()->count())->toBe(0);
    Bus::assertNotDispatched(AnswerChatQuestion::class);
});
