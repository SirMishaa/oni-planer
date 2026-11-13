<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Services\Uplink\ColonyIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * `client-colony.state`: the colony-wide snapshot. Unlike the other message jobs, it does not use the shared reader:
 * the ingestor reads the raw frame itself (it journals refused frames with
 * their size and checks the schema), and the message is never acknowledged.
 */
final class ReceiveColonyState implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    public function handle(ColonyIngestor $ingestor): void
    {
        $ingestor->ingest($this->frame);
    }
}
