<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Answers one in-game chat question with Claude. */
final class AnswerChatQuestion implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $questionId)
    {
        $this->onQueue('chat');
    }

    public function handle(): void {}
}
