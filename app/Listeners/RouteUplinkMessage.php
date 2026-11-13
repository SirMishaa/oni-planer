<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UplinkMessageType;
use Laravel\Reverb\Events\MessageReceived;
use Throwable;

/**
 * Runs inside the Reverb process for every message a client sends. Only
 * routes: reads the envelope, picks the message type and queues its job with
 * the raw frame. It never decodes the payload nor touches the database, so
 * the event loop serving every socket stays free, and it never throws.
 */
final class RouteUplinkMessage
{
    public function handle(MessageReceived $event): void
    {
        try {
            if (! $this->mayBeUplink($event->message)) {
                return;
            }

            $envelope = json_decode($event->message, true);
            $type = null;

            if (is_array($envelope) && ! array_is_list($envelope)) {
                $channel = $envelope['channel'] ?? null;
                $name = $envelope['event'] ?? null;
                $type = UplinkMessageType::fromFrame(is_string($channel) ? $channel : null, is_string($name) ? $name : null);
            }

            // An unreadable or misrouted colony state still goes to its job, which journals the refusal for the inspector.
            if ($type === null && str_contains(mb_substr($event->message, 0, 200, '8bit'), UplinkMessageType::ColonyState->value)) {
                $type = UplinkMessageType::ColonyState;
            }

            if ($type === null) {
                return;
            }

            $job = $type->job();
            dispatch(new $job($event->message))->onQueue($type->queue());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** The mod writes the event name first: a frame without it in its first 200 bytes is not one of its messages. */
    private function mayBeUplink(string $message): bool
    {
        $head = mb_substr($message, 0, 200, '8bit');

        foreach (UplinkMessageType::cases() as $type) {
            if (str_contains($head, $type->value)) {
                return true;
            }
        }

        return false;
    }
}
