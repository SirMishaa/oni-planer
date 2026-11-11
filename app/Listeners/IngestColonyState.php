<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Uplink\ColonyIngestor;
use Laravel\Reverb\Events\MessageReceived;
use Throwable;

/**
 * Runs inside the Reverb process for every message a client sends. It must
 * stay cheap and must never throw, or the Reverb event loop would die.
 */
final class IngestColonyState
{
    public function __construct(private readonly ColonyIngestor $ingestor) {}

    public function handle(MessageReceived $event): void
    {
        if (! str_contains(mb_substr($event->message, 0, 200, '8bit'), ColonyIngestor::EVENT)) {
            return;
        }

        try {
            $this->ingestor->ingest($event->message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
