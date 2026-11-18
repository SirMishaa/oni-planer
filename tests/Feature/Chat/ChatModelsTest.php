<?php

declare(strict_types=1);

use App\Models\ChatContextItem;
use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use App\Models\UplinkCommand;
use App\Models\Zone;

it('stores a conversation, its questions and their context items', function (): void {
    $conversation = ChatConversation::factory()->create(['colony_guid' => 'colony-1']);
    $command = UplinkCommand::factory()->create();
    $zone = Zone::factory()->create();

    $question = ChatQuestion::factory()->for($conversation, 'conversation')->create([
        'status' => ChatQuestion::ANSWERED,
        'answer' => 'Build a second pump.',
        'duration_ms' => 4200,
        'tools' => ['mcp__oni__zone_overview', 'Read'],
        'context_requested_at' => now(),
        'context_command_id' => $command->id,
        'answered_at' => now(),
    ]);
    $item = ChatContextItem::factory()->for($question, 'question')->create([
        'kind' => 'zone',
        'label' => 'Ranch',
        'zone_id' => $zone->id,
        'wants_capture' => true,
    ]);

    $question = ChatQuestion::query()->findOrFail($question->id);

    expect($conversation->questions)->toHaveCount(1)
        ->and($question->conversation->is($conversation))->toBeTrue()
        ->and($question->contextItems->first()->is($item))->toBeTrue()
        ->and($question->contextItems->first()->zone->is($zone))->toBeTrue()
        ->and($question->tools)->toBe(['mcp__oni__zone_overview', 'Read'])
        ->and($question->duration_ms)->toBe(4200)
        ->and($question->status)->toBe(ChatQuestion::ANSWERED)
        ->and($question->context_command_id)->toBe($command->id)
        ->and($item->wants_capture)->toBeTrue();
});

it('keeps the id sent by the mod for conversations and questions', function (): void {
    $conversation = ChatConversation::factory()->create(['id' => '11111111-1111-4111-8111-111111111111']);
    $question = ChatQuestion::factory()->for($conversation, 'conversation')->create(['id' => '22222222-2222-4222-8222-222222222222']);

    expect($conversation->fresh()->id)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($question->fresh()->id)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($question->status)->toBe(ChatQuestion::PENDING);
});
