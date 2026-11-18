<?php

declare(strict_types=1);

use App\Ai\ClaudeCode\ClaudeCodeProvider;
use App\Ai\ClaudeCode\ClaudeCodeRunRecorder;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;

const CLAUDE_FAKE_SESSION = '11111111-1111-4111-8111-111111111111';

beforeEach(function (): void {
    Process::preventStrayProcesses(false);

    $this->argsFile = tempnam(sys_get_temp_dir(), 'claude-args');
    $this->outputFile = tempnam(sys_get_temp_dir(), 'claude-out');
    file_put_contents($this->argsFile, '');

    config()->set('ai.providers.claude-code.binary', base_path('tests/Fixtures/claude-fake'));
    claudeFakeEnv('CLAUDE_FAKE_ARGS_FILE', $this->argsFile);
    claudeFakeEnv('CLAUDE_FAKE_OUTPUT_FILE', $this->outputFile);
});

afterEach(function (): void {
    foreach (['ARGS_FILE', 'OUTPUT_FILE', 'EXIT', 'STDERR', 'SLEEP', 'FAIL_RESUME'] as $name) {
        claudeFakeEnv("CLAUDE_FAKE_{$name}", null);
    }

    @unlink($this->argsFile);
    @unlink($this->outputFile);
});

/**
 * Sets a variable the fake binary reads. Symfony Process only passes on the variables that are also in $_SERVER.
 */
function claudeFakeEnv(string $name, ?string $value): void
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
 * @param  list<array<string, mixed>>  $events  stream-json lines the fake binary prints
 */
function claudeFakeOutput(array $events): void
{
    file_put_contents(
        getenv('CLAUDE_FAKE_OUTPUT_FILE'),
        implode("\n", array_map(fn (array $event): string => json_encode($event), $events))."\n",
    );
}

/**
 * @return array<string, mixed>
 */
function claudeResultEvent(string $text = 'Le bassin est stable.', int $durationMs = 38000, bool $isError = false): array
{
    return [
        'type' => 'result',
        'subtype' => $isError ? 'error_during_execution' : 'success',
        'is_error' => $isError,
        'result' => $text,
        'session_id' => CLAUDE_FAKE_SESSION,
        'duration_ms' => $durationMs,
    ];
}

/**
 * @return list<list<string>>
 */
function claudeCalls(): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true),
        array_values(array_filter(explode("\n", (string) file_get_contents(getenv('CLAUDE_FAKE_ARGS_FILE'))))),
    );
}

/**
 * @param  array<string, mixed>  $options
 * @param  list<Message>  $history
 */
function claudeAgent(array $options = [], array $history = []): Agent
{
    return new class($options, $history) implements Agent, Conversational, HasProviderOptions
    {
        use Promptable;

        public function __construct(private array $options, private array $history) {}

        public function instructions(): string
        {
            return 'Tu es un assistant ONI.';
        }

        public function messages(): iterable
        {
            return $this->history;
        }

        public function providerOptions(Lab|string $provider): array
        {
            return $this->options;
        }
    };
}

it('resolves the claude-code provider through Ai::extend', function (): void {
    expect(Ai::textProvider('claude-code'))->toBeInstanceOf(ClaudeCodeProvider::class);
});

it('answers with the result text of claude -p', function (): void {
    claudeFakeOutput([
        ['type' => 'system', 'subtype' => 'init', 'session_id' => CLAUDE_FAKE_SESSION],
        ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'text', 'text' => 'Je regarde.'],
            ['type' => 'tool_use', 'id' => 't1', 'name' => 'mcp__oni__zone_overview', 'input' => []],
        ]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'ok']]]],
        ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'id' => 't2', 'name' => 'Read', 'input' => []],
        ]]],
        claudeResultEvent(),
    ]);

    $response = claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false])
        ->prompt('Comment va le bassin ?', provider: 'claude-code');

    $run = app(ClaudeCodeRunRecorder::class)->last();

    expect($response->text)->toBe('Le bassin est stable.')
        ->and($run->durationMs)->toBe(38000)
        ->and($run->sessionId)->toBe(CLAUDE_FAKE_SESSION)
        ->and($run->toolNames)->toBe(['mcp__oni__zone_overview', 'Read'])
        ->and($run->isError)->toBeFalse()
        ->and($run->errorMessage)->toBeNull();
});

it('passes the read-only flags', function (): void {
    claudeFakeOutput([claudeResultEvent()]);

    claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false])
        ->prompt('Salut', provider: 'claude-code');

    [$arguments] = claudeCalls();
    $joined = implode(' ', $arguments);

    expect($arguments)->toContain('-p', '--strict-mcp-config', '--verbose')
        ->and($joined)->toContain('--output-format stream-json')
        ->and($joined)->toContain('--permission-mode dontAsk')
        ->and($joined)->toContain('--tools Read,Grep,Glob,Bash')
        ->and($joined)->not->toContain('--dangerously-skip-permissions')
        ->and($joined)->not->toContain('bypassPermissions');

    foreach (config('ai.providers.claude-code.allowed_tools') as $tool) {
        expect($arguments)->toContain($tool);
    }

    $mcpConfig = json_decode($arguments[array_search('--mcp-config', $arguments, true) + 1], true);

    expect(array_keys($mcpConfig['mcpServers']))->toBe(['oni']);
});

it('starts a new session with --session-id and continues with --resume', function (): void {
    claudeFakeOutput([claudeResultEvent()]);

    claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false])->prompt('Un', provider: 'claude-code');
    claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => true])->prompt('Deux', provider: 'claude-code');

    [$first, $second] = claudeCalls();

    expect($first)->toContain('--session-id', CLAUDE_FAKE_SESSION)
        ->and($first)->not->toContain('--resume')
        ->and($second)->toContain('--resume', CLAUDE_FAKE_SESSION)
        ->and($second)->not->toContain('--session-id');
});

it('retries once with a new session when resume fails', function (): void {
    claudeFakeOutput([claudeResultEvent('Réponse après reprise.')]);
    claudeFakeEnv('CLAUDE_FAKE_FAIL_RESUME', '1');

    $history = [
        new UserMessage('Où sont les Hatchs ?'),
        new Message('assistant', 'Dans la zone nord.'),
    ];

    $response = claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => true], $history)
        ->prompt('Et les Pufts ?', provider: 'claude-code');

    $calls = claudeCalls();
    $retry = $calls[1];
    $retrySession = $retry[array_search('--session-id', $retry, true) + 1];
    $retryPrompt = $retry[1];

    expect($calls)->toHaveCount(2)
        ->and($calls[0])->toContain('--resume')
        ->and($retry)->not->toContain('--resume')
        ->and($retrySession)->not->toBe(CLAUDE_FAKE_SESSION)
        ->and($retryPrompt)->toContain('Previous conversation:')
        ->and($retryPrompt)->toContain('Où sont les Hatchs ?')
        ->and($retryPrompt)->toContain('Dans la zone nord.')
        ->and($retryPrompt)->toEndWith('Et les Pufts ?')
        ->and($response->text)->toBe('Réponse après reprise.');
});

it('sends only the last user message and the image paths on a fresh session', function (): void {
    claudeFakeOutput([claudeResultEvent()]);

    claudeAgent(
        ['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false, 'image_paths' => ['/tmp/a.png', '/tmp/b.png']],
        [new UserMessage('Ancienne question')],
    )->prompt('Nouvelle question', provider: 'claude-code');

    [$arguments] = claudeCalls();

    expect($arguments[1])->toContain('Nouvelle question')
        ->and($arguments[1])->toContain('Images to read: /tmp/a.png, /tmp/b.png')
        ->and($arguments[1])->not->toContain('Ancienne question');
});

it('reports a failed run as an error', function (): void {
    claudeFakeEnv('CLAUDE_FAKE_EXIT', '1');
    claudeFakeEnv('CLAUDE_FAKE_STDERR', 'claude: not logged in');
    file_put_contents($this->outputFile, '');

    $provider = Ai::textProvider('claude-code');
    $step = $provider->textGateway()->generateTextStep(
        $provider,
        'opus',
        null,
        [new UserMessage('Salut')],
        [],
        null,
        new TextGenerationOptions(providerOptions: ['claude-code' => ['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false]]),
        null,
        new StepContext,
    );

    $run = app(ClaudeCodeRunRecorder::class)->last();

    expect($step->finishReason)->toBe(FinishReason::Error)
        ->and($run->isError)->toBeTrue()
        ->and($run->errorMessage)->toBe('claude: not logged in');
});

it('kills claude after the timeout', function (): void {
    config()->set('ai.providers.claude-code.timeout', 1);
    claudeFakeEnv('CLAUDE_FAKE_SLEEP', '3');

    $provider = Ai::textProvider('claude-code');
    $step = $provider->textGateway()->generateTextStep(
        $provider,
        'opus',
        null,
        [new UserMessage('Salut')],
        [],
        null,
        new TextGenerationOptions(providerOptions: ['claude-code' => ['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false]]),
        null,
        new StepContext,
    );

    $run = app(ClaudeCodeRunRecorder::class)->last();

    expect($step->finishReason)->toBe(FinishReason::Error)
        ->and($run->isError)->toBeTrue()
        ->and($run->errorMessage)->toContain('timed out after 1 s')
        ->and(claudeCalls())->toHaveCount(1);
});

it('does not retry a resumed run that produced an error result', function (): void {
    claudeFakeOutput([claudeResultEvent('Tool crashed', isError: true)]);
    claudeFakeEnv('CLAUDE_FAKE_EXIT', '1');

    $provider = Ai::textProvider('claude-code');
    $step = $provider->textGateway()->generateTextStep(
        $provider,
        'opus',
        null,
        [new UserMessage('Avant'), new Message('assistant', 'Réponse'), new UserMessage('Salut')],
        [],
        null,
        new TextGenerationOptions(providerOptions: ['claude-code' => ['session_id' => CLAUDE_FAKE_SESSION, 'resume' => true]]),
        null,
        new StepContext,
    );

    expect(claudeCalls())->toHaveCount(1)
        ->and($step->finishReason)->toBe(FinishReason::Error)
        ->and(app(ClaudeCodeRunRecorder::class)->last()->errorMessage)->toBe('Tool crashed');
});

it('passes the bash, MCP wildcard and every deny rule', function (): void {
    claudeFakeOutput([claudeResultEvent()]);

    claudeAgent(['session_id' => CLAUDE_FAKE_SESSION, 'resume' => false])->prompt('Salut', provider: 'claude-code');

    [$arguments] = claudeCalls();
    $allowed = array_slice($arguments, array_search('--allowedTools', $arguments, true) + 1);
    $denied = array_slice($arguments, array_search('--disallowedTools', $arguments, true) + 1);

    expect($allowed)->toContain('Bash(rg *)', 'Bash(ilspycmd *)', 'mcp__oni__*')
        ->and($denied)->toBe([
            'Read(.env)',
            'Read(.env.*)',
            'Edit',
            'Bash(rg *.env*)',
            'Bash(rg *--hidden*)',
            'Bash(rg *--no-ignore*)',
            'Bash(rg *--ignore-file*)',
            'Bash(rg *--glob*)',
            'Bash(rg *--pre*)',
            'Bash(rg * -u*)',
            'Bash(rg * -g*)',
            'Bash(ilspycmd -o*)',
            'Bash(ilspycmd * -o*)',
            'Bash(ilspycmd *--outputdir*)',
            'Bash(ilspycmd -p*)',
            'Bash(ilspycmd * -p*)',
            'Bash(ilspycmd *--project*)',
            'Bash(ilspycmd *-genpdb*)',
            'Bash(ilspycmd *--generate-pdb*)',
            'Bash(ilspycmd -d*)',
            'Bash(ilspycmd * -d*)',
            'Bash(ilspycmd *--dump-package*)',
            'Bash(ilspycmd *--generate-diagrammer*)',
        ]);
});
