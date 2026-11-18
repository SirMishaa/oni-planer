<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Actions\Chat\SendChatReply;
use App\Ai\Agents\ColonyChatAgent;
use App\Ai\ClaudeCode\ClaudeCodeRunRecorder;
use App\Models\ChatContextItem;
use App\Models\ChatQuestion;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Services\Chat\ChatPrompt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answers one in-game chat question with Claude: waits for the context the game was asked to
 * send, prompts the agent and sends the answer (or the error) back to the game.
 */
final class AnswerChatQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int CONTEXT_WAIT_SECONDS = 20;

    public const int CONTEXT_POLL_MILLISECONDS = 500;

    /** Seconds before the `claude` process timeout (300 s) plus the context wait (20 s) and some margin. */
    public const int MAX_RUN_SECONDS = 360;

    public int $timeout = self::MAX_RUN_SECONDS;

    public int $tries = 1;

    public function __construct(public readonly string $questionId)
    {
        $this->onQueue('chat');
    }

    /** One queued job per question, so a resent ask that re-runs the tail cannot answer twice. */
    public function uniqueId(): string
    {
        return $this->questionId;
    }

    public function handle(SendChatReply $sendReply): void
    {
        $claimed = ChatQuestion::query()
            ->whereKey($this->questionId)
            ->where('status', ChatQuestion::PENDING)
            ->update(['status' => ChatQuestion::WORKING]);

        if ($claimed === 0) {
            return;
        }

        $question = ChatQuestion::query()->with('conversation')->findOrFail($this->questionId);

        $sendReply->handle($question, SendChatReply::KIND_WORKING, '');

        try {
            $missingItemIds = $this->awaitContext($question);
            $this->prepareSession($question);

            $agent = new ColonyChatAgent($question, ChatPrompt::imagePaths($question));
            $response = $agent->prompt(ChatPrompt::build($question, $missingItemIds), provider: 'claude-code');
            $run = app(ClaudeCodeRunRecorder::class)->last();
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($question, $sendReply, 'Claude could not answer: '.$exception->getMessage());

            return;
        }

        if ($run === null || $run->isError) {
            $this->markFailed($question, $sendReply, $run?->errorMessage ?? 'Claude returned no answer.', $run?->durationMs);

            return;
        }

        $tools = array_values(array_unique($run->toolNames));

        $question->update([
            'status' => ChatQuestion::ANSWERED,
            'answer' => $response->text,
            'duration_ms' => $run->durationMs,
            'tools' => $tools,
            'answered_at' => now(),
        ]);
        $question->conversation->update(['claude_session_id' => $run->sessionId]);

        $sendReply->handle($question, SendChatReply::KIND_ANSWER, $response->text, $run->durationMs, $tools);
    }

    /** Called when the worker kills or loses the job, so the player is not left waiting. */
    public function failed(Throwable $exception): void
    {
        $question = ChatQuestion::query()->with('conversation')->find($this->questionId);

        if ($question === null || in_array($question->status, [ChatQuestion::ANSWERED, ChatQuestion::FAILED], true)) {
            return;
        }

        $this->markFailed($question, app(SendChatReply::class), 'Claude could not answer: '.$exception->getMessage());
    }

    /**
     * Waits until the game sent what the question asked for, or gives up.
     *
     * @return list<int> The ids of the context items whose data is still missing.
     */
    private function awaitContext(ChatQuestion $question): array
    {
        $polls = intdiv(self::CONTEXT_WAIT_SECONDS * 1000, self::CONTEXT_POLL_MILLISECONDS);

        for ($poll = 0; ; $poll++) {
            $missing = $this->missingItemIds($question);

            if ($missing === [] || $poll >= $polls || $this->contextCommandIsSettled($question)) {
                return $missing;
            }

            Sleep::for(self::CONTEXT_POLL_MILLISECONDS)->milliseconds();
        }
    }

    /** True when the mod refused the context command or it expired without an answer. */
    private function contextCommandIsSettled(ChatQuestion $question): bool
    {
        if ($question->context_command_id === null) {
            return true;
        }

        $status = UplinkCommand::query()->find($question->context_command_id)?->status;

        return in_array($status, [UplinkCommand::REFUSED, UplinkCommand::EXPIRED], true);
    }

    /**
     * @return list<int>
     */
    private function missingItemIds(ChatQuestion $question): array
    {
        $missing = [];

        foreach ($question->contextItems()->with('zone')->get() as $item) {
            if ($item->refused_reason === null && ! $this->hasData($item, $question)) {
                $missing[] = $item->id;
            }
        }

        return $missing;
    }

    private function hasData(ChatContextItem $item, ChatQuestion $question): bool
    {
        $zone = $item->zone;

        if ($zone === null) {
            return true;
        }

        $zone->refresh();

        if ($zone->transient && ($zone->layout === null || $zone->slow_state === null || $zone->fast_state === null)) {
            return false;
        }

        if ($item->wants_capture && $this->hasFreshCapture($zone, $question) === false) {
            return false;
        }

        return true;
    }

    private function hasFreshCapture(Zone $zone, ChatQuestion $question): bool
    {
        return $zone->latestCapture($question->context_requested_at) !== null;
    }

    /** A conversation resumes only a session that answered before; otherwise it starts a new one. */
    private function prepareSession(ChatQuestion $question): void
    {
        $conversation = $question->conversation;
        $hasAnswered = $conversation->questions()->where('status', ChatQuestion::ANSWERED)->exists();

        if (! $hasAnswered || $conversation->claude_session_id === null) {
            $conversation->update(['claude_session_id' => (string) Str::uuid()]);
        }
    }

    private function markFailed(ChatQuestion $question, SendChatReply $sendReply, string $message, ?int $durationMs = null): void
    {
        Log::warning('Chat question failed', ['question' => $question->id, 'error' => $message]);

        $question->update(['status' => ChatQuestion::FAILED, 'error' => $message, 'duration_ms' => $durationMs]);
        $sendReply->handle($question, SendChatReply::KIND_ERROR, $message);
    }
}
