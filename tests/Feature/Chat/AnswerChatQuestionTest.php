<?php

declare(strict_types=1);

use App\Events\Uplink\UplinkCommandIssued;
use App\Jobs\Chat\AnswerChatQuestion;
use App\Models\ChatContextItem;
use App\Models\ChatConversation;
use App\Models\ChatQuestion;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const CHAT_FAKE_SESSION = '22222222-2222-4222-8222-222222222222';

beforeEach(function (): void {
    Process::preventStrayProcesses(false);
    Event::fake([UplinkCommandIssued::class]);
    Sleep::fake();
    Sleep::syncWithCarbon();

    $this->argsFile = tempnam(sys_get_temp_dir(), 'chat-args');
    $this->outputFile = tempnam(sys_get_temp_dir(), 'chat-out');
    file_put_contents($this->argsFile, '');

    config()->set('ai.providers.claude-code.binary', base_path('tests/Fixtures/claude-fake'));
    chatFakeEnv('CLAUDE_FAKE_ARGS_FILE', $this->argsFile);
    chatFakeEnv('CLAUDE_FAKE_OUTPUT_FILE', $this->outputFile);
    chatFakeResult();

    Colony::factory()->create(['guid' => 'colony-1', 'name' => 'Nouvelle Base', 'cycle' => 142]);
    $this->conversation = ChatConversation::factory()->create(['colony_guid' => 'colony-1']);
    $this->question = ChatQuestion::factory()->for($this->conversation, 'conversation')->create(['question' => 'Pourquoi il fait chaud ?']);
});

afterEach(function (): void {
    foreach (['ARGS_FILE', 'OUTPUT_FILE', 'FAIL_RESUME'] as $name) {
        chatFakeEnv("CLAUDE_FAKE_{$name}", null);
    }

    @unlink($this->argsFile);
    @unlink($this->outputFile);
});

function chatFakeEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_SERVER[$name]);

        return;
    }

    putenv("{$name}={$value}");
    $_SERVER[$name] = $value;
}

/**
 * @param  list<string>  $tools
 */
function chatFakeResult(string $text = 'Le bassin chauffe.', bool $isError = false, array $tools = ['mcp__oni__zone_overview']): void
{
    $events = [['type' => 'assistant', 'message' => ['content' => array_map(
        fn (string $tool): array => ['type' => 'tool_use', 'id' => $tool, 'name' => $tool, 'input' => []],
        $tools,
    )]]];
    $events[] = [
        'type' => 'result', 'subtype' => $isError ? 'error_during_execution' : 'success', 'is_error' => $isError,
        'result' => $text, 'session_id' => CHAT_FAKE_SESSION, 'duration_ms' => 4200,
    ];

    file_put_contents(getenv('CLAUDE_FAKE_OUTPUT_FILE'), implode("\n", array_map('json_encode', $events))."\n");
}

/** @return list<list<string>> */
function chatClaudeCalls(): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true),
        array_values(array_filter(explode("\n", (string) file_get_contents(getenv('CLAUDE_FAKE_ARGS_FILE'))))),
    );
}

/** @return list<UplinkCommand> */
function chatReplies(): array
{
    return UplinkCommand::query()->where('type', 'chat.reply')->orderBy('issued_at')->orderBy('id')->get()->all();
}

function runAnswerJob(ChatQuestion $question): void
{
    app()->call([new AnswerChatQuestion($question->id), 'handle']);
}

it('sends working, then the answer with its duration and tools', function (): void {
    runAnswerJob($this->question);

    $replies = collect(chatReplies())->keyBy(fn (UplinkCommand $command): string => $command->payload['kind']);
    $question = $this->question->fresh();

    expect($replies)->toHaveCount(2)
        ->and($replies['working']->colony_guid)->toBe('colony-1')
        ->and($replies['answer']->payload)->toMatchArray([
            'conversationId' => $this->conversation->id, 'questionId' => $question->id,
            'text' => 'Le bassin chauffe.', 'durationMs' => 4200, 'tools' => ['mcp__oni__zone_overview'],
        ])
        ->and($replies['answer']->expires_at->diffInSeconds($replies['answer']->issued_at, true))->toBeGreaterThan(100)
        ->and($question->status)->toBe(ChatQuestion::ANSWERED)
        ->and($question->answer)->toBe('Le bassin chauffe.')
        ->and($question->duration_ms)->toBe(4200)
        ->and($question->tools)->toBe(['mcp__oni__zone_overview'])
        ->and($question->answered_at)->not->toBeNull()
        ->and($this->conversation->fresh()->claude_session_id)->toBe(CHAT_FAKE_SESSION);

    [$call] = chatClaudeCalls();
    expect($call)->toContain('--session-id')
        ->and(implode(' ', $call))->toContain('Colony: Nouvelle Base, cycle 142.')
        ->and(implode(' ', $call))->toContain('Pourquoi il fait chaud ?');
});

it('does nothing for a question that is no longer pending', function (): void {
    $this->question->update(['status' => ChatQuestion::WORKING]);

    runAnswerJob($this->question);

    expect(chatReplies())->toBe([])
        ->and(chatClaudeCalls())->toBe([]);
});

it('waits for the transient zone data before prompting', function (): void {
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'transient' => true, 'layout' => null]);
    ChatContextItem::factory()->for($this->question, 'question')->create(['kind' => 'box', 'label' => 'Boite', 'zone_id' => $zone->id, 'layer_hint' => 'gas']);
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'chat.context', 'expires_at' => now()->addSeconds(30)]);
    $this->question->update(['context_command_id' => $command->id, 'context_requested_at' => now()]);

    // The game sends the data while the job sleeps between two polls.
    Sleep::whenFakingSleep(function () use ($zone): void {
        $zone->update(['layout' => ['cells' => []], 'slow_state' => ['t' => 1], 'fast_state' => ['t' => 1]]);
    });

    runAnswerJob($this->question);

    $prompt = implode(' ', chatClaudeCalls()[0]);

    Sleep::assertSleptTimes(1);
    expect($prompt)->toContain("Zone id for zone_overview, zone_map and entity: {$zone->id}")
        ->and($prompt)->toContain('Layer hint (a hint only, not a filter): gas')
        ->and($prompt)->not->toContain('Could not be read from the game')
        ->and($this->question->fresh()->status)->toBe(ChatQuestion::ANSWERED);
});

it('passes a fresh capture to the prompt and the image paths', function (): void {
    Storage::fake('public');
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1']);
    ChatContextItem::factory()->for($this->question, 'question')->create(['kind' => 'zone', 'label' => 'Ferme', 'zone_id' => $zone->id, 'wants_capture' => true]);
    $this->question->update(['context_requested_at' => now()->subSecond()]);
    $capture = ZoneCapture::factory()->for($zone)->create(['taken_at' => now(), 'path' => 'zone-captures/z/new.webp']);

    runAnswerJob($this->question);

    $joined = implode(' ', chatClaudeCalls()[0]);
    expect($joined)->toContain(Storage::disk('public')->path($capture->path));
});

it('answers with what it has when the game does not send the context in time', function (): void {
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'transient' => true, 'layout' => null]);
    ChatContextItem::factory()->for($this->question, 'question')->create(['kind' => 'box', 'label' => 'Boite', 'zone_id' => $zone->id]);
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'chat.context', 'expires_at' => now()->addSeconds(30)]);
    $this->question->update(['context_command_id' => $command->id, 'context_requested_at' => now()]);

    runAnswerJob($this->question);

    expect(implode(' ', chatClaudeCalls()[0]))->toContain('Could not be read from the game: box "Boite"')
        ->and($this->question->fresh()->status)->toBe(ChatQuestion::ANSWERED);
    Sleep::assertSleptTimes(40);
});

it('does not wait for items the mod refused', function (): void {
    $zone = Zone::factory()->create(['colony_guid' => 'colony-1', 'transient' => true, 'layout' => null]);
    ChatContextItem::factory()->for($this->question, 'question')->create(['kind' => 'room', 'label' => 'Salle', 'zone_id' => $zone->id]);
    $command = UplinkCommand::factory()->create(['colony_guid' => 'colony-1', 'type' => 'chat.context', 'status' => UplinkCommand::REFUSED]);
    $this->question->update(['context_command_id' => $command->id, 'context_requested_at' => now()]);

    runAnswerJob($this->question);

    Sleep::assertNeverSlept();
    expect(implode(' ', chatClaudeCalls()[0]))->toContain('Could not be read from the game: room "Salle"');
});

it('sends an error reply when claude fails', function (): void {
    chatFakeResult('Credit balance is too low', isError: true);

    runAnswerJob($this->question);

    $question = $this->question->fresh();
    $kinds = collect(chatReplies())->map(fn (UplinkCommand $command): string => $command->payload['kind'])->sort()->values()->all();
    $error = collect(chatReplies())->firstWhere(fn (UplinkCommand $command): bool => $command->payload['kind'] === 'error');

    expect($kinds)->toBe(['error', 'working'])
        ->and($error->payload['text'])->toContain('Credit balance is too low')
        ->and($question->status)->toBe(ChatQuestion::FAILED)
        ->and($question->error)->toContain('Credit balance is too low')
        ->and($question->answer)->toBeNull();
});

it('resumes the conversation session for a follow-up question', function (): void {
    $this->conversation->update(['claude_session_id' => CHAT_FAKE_SESSION]);
    ChatQuestion::factory()->for($this->conversation, 'conversation')->create([
        'question' => 'Avant ?', 'status' => ChatQuestion::ANSWERED, 'answer' => 'Avant.', 'created_at' => now()->subMinute(),
    ]);

    runAnswerJob($this->question);

    [$call] = chatClaudeCalls();
    expect($call)->toContain('--resume', CHAT_FAKE_SESSION)
        ->and($call)->not->toContain('--session-id');
});

it('replays the earlier questions when the session cannot be resumed', function (): void {
    $this->conversation->update(['claude_session_id' => CHAT_FAKE_SESSION]);
    ChatQuestion::factory()->for($this->conversation, 'conversation')->create([
        'question' => 'Avant ?', 'status' => ChatQuestion::ANSWERED, 'answer' => 'Avant.', 'created_at' => now()->subMinute(),
    ]);
    chatFakeEnv('CLAUDE_FAKE_FAIL_RESUME', '1');

    runAnswerJob($this->question);

    $calls = chatClaudeCalls();
    expect($calls)->toHaveCount(2)
        ->and(implode(' ', $calls[1]))->toContain('Previous conversation:')
        ->and(implode(' ', $calls[1]))->toContain('Avant.')
        ->and($this->question->fresh()->status)->toBe(ChatQuestion::ANSWERED);
});
