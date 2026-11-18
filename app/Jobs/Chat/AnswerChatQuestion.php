<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Answers one in-game chat question with Claude. */
final class AnswerChatQuestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $questionId)
    {
        $this->onQueue('chat');
    }

    /** One queued job per question, so a resent ask that re-runs the tail cannot answer twice. */
    public function uniqueId(): string
    {
        return $this->questionId;
    }

    public function handle(): void {}
}
