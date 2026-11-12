<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Uplink\StartUplinkSession;
use App\Actions\Uplink\StoreZoneLayout;
use App\Actions\Uplink\StoreZoneState;
use App\Actions\Uplink\SyncZoneList;
use App\Services\Uplink\UplinkPayload;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Events\MessageReceived;
use Throwable;

/**
 * Runs inside the Reverb process for every message a client sends. Handles the
 * zone messages of the mod on the `uplink-data` channel: it must stay cheap
 * and must never throw, or the Reverb event loop would die. Reliable messages
 * (session, list, layout) are acknowledged to the sender once stored; states
 * are latest-only and never acknowledged.
 */
final class IngestUplinkData
{
    public const string CHANNEL = 'uplink-data';

    public const string ACK_EVENT = 'uplink.ack';

    public const int SCHEMA = 1;

    private const array RELIABLE_EVENTS = ['client-session.start', 'client-zone.list', 'client-zone.layout'];

    private const array STATE_EVENTS = ['client-zone.slow' => 'slow', 'client-zone.fast' => 'fast'];

    /**
     * Colonies whose newer-schema refusal was already logged. Reverb is a
     * single long-running process, so a per-process set logs it once per colony.
     *
     * @var array<string, true>
     */
    private static array $loggedNewerSchema = [];

    public function __construct(
        private readonly StartUplinkSession $startSession,
        private readonly SyncZoneList $syncZoneList,
        private readonly StoreZoneLayout $storeLayout,
        private readonly StoreZoneState $storeState,
    ) {}

    public function handle(MessageReceived $event): void
    {
        if (! $this->looksLikeUplinkData($event->message)) {
            return;
        }

        try {
            $envelope = json_decode($event->message, true, flags: JSON_THROW_ON_ERROR);
            $eventName = is_array($envelope) ? ($envelope['event'] ?? null) : null;

            if (($envelope['channel'] ?? null) !== self::CHANNEL
                || ! is_string($eventName)
                || ! (in_array($eventName, self::RELIABLE_EVENTS, true) || isset(self::STATE_EVENTS[$eventName]))) {
                return;
            }

            $message = UplinkPayload::decode($envelope['data'] ?? null);

            if ($message === null || ! $this->isAcceptedSchema($message)) {
                return;
            }

            if (in_array($eventName, self::RELIABLE_EVENTS, true)) {
                $this->ingestReliable($event, $eventName, $message);

                return;
            }

            if (is_string($message['colonyId'] ?? null) && is_string($message['zoneId'] ?? null)) {
                $this->storeState->handle(self::STATE_EVENTS[$eventName], $message);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function ingestReliable(MessageReceived $event, string $eventName, array $message): void
    {
        $id = $message['id'] ?? null;

        if (! is_string($id) || ! is_string($message['colonyId'] ?? null)) {
            return;
        }

        $stored = match ($eventName) {
            'client-session.start' => $this->storeSession($message),
            'client-zone.list' => $this->storeList($message),
            default => is_string($message['zoneId'] ?? null) && is_array($message['cells'] ?? null) && $this->storeLayout->handle($message),
        };

        if ($stored) {
            $event->connection->send((string) json_encode([
                'event' => self::ACK_EVENT,
                'channel' => self::CHANNEL,
                'data' => json_encode(['id' => $id]),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeSession(array $message): bool
    {
        $this->startSession->handle($message);

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeList(array $message): bool
    {
        if (! is_array($message['zones'] ?? null)) {
            return false;
        }

        $this->syncZoneList->handle($message);

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isAcceptedSchema(array $message): bool
    {
        $schema = $message['schema'] ?? null;

        if (! is_int($schema) || $schema < 1) {
            return false;
        }

        if ($schema > self::SCHEMA) {
            $colony = is_string($message['colonyId'] ?? null) ? $message['colonyId'] : '?';

            if (! isset(self::$loggedNewerSchema[$colony])) {
                self::$loggedNewerSchema[$colony] = true;
                Log::warning("Uplink: refused zone messages of colony {$colony}, schema {$schema} is newer than {self::SCHEMA}");
            }

            return false;
        }

        return true;
    }

    private function looksLikeUplinkData(string $message): bool
    {
        $head = mb_substr($message, 0, 200, '8bit');

        if (str_contains($head, self::CHANNEL)) {
            return true;
        }

        foreach ([...self::RELIABLE_EVENTS, ...array_keys(self::STATE_EVENTS)] as $name) {
            if (str_contains($head, $name)) {
                return true;
            }
        }

        return false;
    }
}
