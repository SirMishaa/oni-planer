<?php

declare(strict_types=1);

namespace App\Ai\ClaudeCode;

/**
 * Keeps the last run of the current request or job, so a caller can read the metadata the SDK
 * response does not carry. Bound as scoped: never shared between requests under Octane.
 */
final class ClaudeCodeRunRecorder
{
    private ?ClaudeCodeRun $last = null;

    public function record(ClaudeCodeRun $run): void
    {
        $this->last = $run;
    }

    public function last(): ?ClaudeCodeRun
    {
        return $this->last;
    }
}
