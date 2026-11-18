<?php

declare(strict_types=1);

namespace App\Ai\ClaudeCode;

use Generator;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use LogicException;

/**
 * Runs `claude -p` once per step. Claude Code runs its own tool loop, so a step is a whole answer.
 *
 * The prompt is the last user message only: the conversation lives in the Claude Code session.
 * The earlier messages are replayed only when a `--resume` fails and a fresh session is started.
 */
final class ClaudeCodeGateway implements StepTextGateway
{
    /**
     * @param  array<string, mixed>  $config  The `ai.providers.claude-code` configuration.
     */
    public function __construct(private readonly array $config) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $providerOptions = $options?->providerOptions('claude-code') ?? [];
        $sessionId = $providerOptions['session_id'] ?? (string) Str::uuid();
        $resume = (bool) ($providerOptions['resume'] ?? false);

        [$earlierMessages, $question] = $this->splitMessages($messages);

        if (($providerOptions['image_paths'] ?? []) !== []) {
            $question .= "\n\nImages to read: ".implode(', ', $providerOptions['image_paths']);
        }

        $run = $this->run($model, $instructions, $question, $sessionId, $resume);

        if ($resume && $run['resumeFailed']) {
            $run = $this->run($model, $instructions, $this->replayPrompt($earlierMessages, $question), (string) Str::uuid(), false);
        }

        resolve(ClaudeCodeRunRecorder::class)->record($run['run']);

        return new StepResponse(
            $run['run']->isError ? (string) $run['run']->errorMessage : $run['text'],
            [],
            $run['run']->isError ? FinishReason::Error : FinishReason::Stop,
            $run['usage'],
            new Meta('claude-code', $model),
        );
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new LogicException('streaming is not supported');
    }

    /**
     * The claude process's extra variables: the configured extra path ahead of the inherited PATH.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $extraPath = $this->config['extra_path'] ?? null;

        if (! is_string($extraPath) || $extraPath === '') {
            return [];
        }

        $path = (string) getenv('PATH');

        return ['PATH' => $path === '' ? $extraPath : $extraPath.PATH_SEPARATOR.$path];
    }

    /**
     * @return array{run: ClaudeCodeRun, text: string, usage: TextUsage, resumeFailed: bool}
     */
    private function run(string $model, ?string $instructions, string $prompt, string $sessionId, bool $resume): array
    {
        $arguments = ClaudeCodeCommand::arguments($this->config, $model, $instructions, $prompt, $sessionId, $resume);

        try {
            $result = Process::path($this->config['working_directory'])
                ->env($this->environment())
                ->timeout($this->config['timeout'])
                ->run([$this->config['binary'], ...$arguments]);
        } catch (ProcessTimedOutException) {
            return $this->failure($sessionId, "claude timed out after {$this->config['timeout']} s");
        }

        $parsed = $this->parseStream($result->output());
        $isError = ! $result->successful() || $parsed['isError'];

        if (! $isError && $parsed['result'] === null) {
            return $this->failure($sessionId, 'claude returned no result');
        }

        if ($isError) {
            $stderr = mb_trim($result->errorOutput());

            return $this->failure(
                $parsed['sessionId'] ?? $sessionId,
                $stderr !== '' ? $stderr : ($parsed['result'] ?: "claude exited with code {$result->exitCode()}"),
                $parsed['durationMs'],
                $parsed['toolNames'],
                resumeFailed: $resume && ($parsed['result'] === null || preg_match('/No conversation found/i', $stderr) === 1),
            );
        }

        return [
            'run' => new ClaudeCodeRun($parsed['sessionId'] ?? $sessionId, $parsed['durationMs'], $parsed['toolNames'], false, null),
            'text' => $parsed['result'],
            'usage' => $parsed['usage'],
            'resumeFailed' => false,
        ];
    }

    /**
     * @param  list<string>  $toolNames
     * @return array{run: ClaudeCodeRun, text: string, usage: TextUsage, resumeFailed: bool}
     */
    private function failure(string $sessionId, string $message, int $durationMs = 0, array $toolNames = [], bool $resumeFailed = false): array
    {
        return [
            'run' => new ClaudeCodeRun($sessionId, $durationMs, $toolNames, true, $message),
            'text' => '',
            'usage' => new TextUsage,
            'resumeFailed' => $resumeFailed,
        ];
    }

    /**
     * Reads the stream-json lines: tool names come from the assistant events, the answer from the final result event.
     *
     * @return array{result: ?string, sessionId: ?string, durationMs: int, isError: bool, toolNames: list<string>, usage: TextUsage}
     */
    private function parseStream(string $output): array
    {
        $parsed = ['result' => null, 'sessionId' => null, 'durationMs' => 0, 'isError' => false, 'toolNames' => [], 'usage' => new TextUsage];

        foreach (preg_split('/\R/', $output, flags: PREG_SPLIT_NO_EMPTY) as $line) {
            $event = json_decode($line, true);

            if (! is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) === 'assistant') {
                foreach ($event['message']['content'] ?? [] as $block) {
                    if (($block['type'] ?? null) === 'tool_use') {
                        $parsed['toolNames'][] = (string) $block['name'];
                    }
                }
            }

            if (($event['type'] ?? null) === 'result') {
                $parsed['result'] = (string) ($event['result'] ?? '');
                $parsed['sessionId'] = $event['session_id'] ?? null;
                $parsed['durationMs'] = (int) ($event['duration_ms'] ?? 0);
                $parsed['isError'] = (bool) ($event['is_error'] ?? false);
                $parsed['usage'] = new TextUsage(
                    (int) ($event['usage']['input_tokens'] ?? 0),
                    (int) ($event['usage']['output_tokens'] ?? 0),
                );
            }
        }

        return $parsed;
    }

    /**
     * @param  array<int, Message>  $messages
     * @return array{0: list<Message>, 1: string} The user and assistant messages before the last user message, then that message.
     */
    private function splitMessages(array $messages): array
    {
        $conversation = array_values(array_filter(
            $messages,
            fn (Message $message): bool => in_array($message->role, [MessageRole::User, MessageRole::Assistant], true),
        ));

        $lastUserIndex = null;

        foreach ($conversation as $index => $message) {
            if ($message->role === MessageRole::User) {
                $lastUserIndex = $index;
            }
        }

        if ($lastUserIndex === null) {
            throw new LogicException('claude-code needs a user message to answer.');
        }

        return [array_slice($conversation, 0, $lastUserIndex), (string) $conversation[$lastUserIndex]->content];
    }

    /**
     * @param  list<Message>  $earlierMessages
     */
    private function replayPrompt(array $earlierMessages, string $question): string
    {
        if ($earlierMessages === []) {
            return $question;
        }

        $transcript = array_map(
            fn (Message $message): string => ($message->role === MessageRole::User ? 'User' : 'Assistant').': '.$message->content,
            $earlierMessages,
        );

        return "Previous conversation:\n".implode("\n", $transcript)."\n\nNew question:\n".$question;
    }
}
