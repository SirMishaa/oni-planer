<?php

declare(strict_types=1);

namespace App\Ai\ClaudeCode;

/**
 * What one `claude -p` run did, as read from its stream-json output.
 */
final readonly class ClaudeCodeRun
{
    /**
     * @param  list<string>  $toolNames  Every tool the run called, in call order (e.g. `mcp__oni__zone_overview`).
     */
    public function __construct(
        public string $sessionId,
        public int $durationMs,
        public array $toolNames,
        public bool $isError,
        public ?string $errorMessage,
    ) {}
}
