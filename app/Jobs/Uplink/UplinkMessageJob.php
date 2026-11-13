<?php

declare(strict_types=1);

namespace App\Jobs\Uplink;

use App\Events\Uplink\UplinkMessageAccepted;
use App\Services\Uplink\UplinkPayload;
use App\Uplink\UplinkMessageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles one message of the mod, off the Reverb process: decodes the raw
 * frame, refuses a schema newer than its type knows, processes it and, for
 * a reliable type, acknowledges it once stored.
 */
abstract class UplinkMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $frame) {}

    abstract public static function type(): UplinkMessageType;

    /**
     * Stores the message. Returns whether it was stored, which acknowledges a
     * reliable message (a refused one is resent by the mod).
     *
     * @param  array<string, mixed>  $message
     */
    abstract protected function process(array $message): bool;

    final public function handle(): void
    {
        $envelope = json_decode($this->frame, true);
        $message = is_array($envelope) ? UplinkPayload::decode($envelope['data'] ?? null) : null;

        if ($message === null || ! $this->isAcceptedSchema($message)) {
            return;
        }

        $stored = $this->process($message);

        if ($stored && static::type()->isReliable() && is_string($message['id'] ?? null)) {
            UplinkMessageAccepted::dispatch($message['id']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isAcceptedSchema(array $message): bool
    {
        $schema = $message['schema'] ?? null;
        $type = static::type();

        if (! is_int($schema) || $schema < 1) {
            return false;
        }

        if ($schema > $type->schema()) {
            $colony = is_string($message['colonyId'] ?? null) ? $message['colonyId'] : '?';

            if (Cache::add("uplink:newer-schema:{$type->value}:{$colony}", true, now()->addHour())) {
                Log::warning("Uplink: refused {$type->value} of colony {$colony}, schema {$schema} is newer than {$type->schema()}");
            }

            return false;
        }

        return true;
    }
}
