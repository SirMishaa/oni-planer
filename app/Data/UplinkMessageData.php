<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\UplinkMessage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * One journalled Uplink message, without its payload. Outcome and reason are
 * codes, translated by the page.
 */
final class UplinkMessageData extends Data
{
    public function __construct(
        public int $id,
        public ?string $event,
        public ?string $colonyGuid,
        public ?string $colonyName,
        public ?int $cycle,
        /** Size of the raw message in bytes. */
        public int $size,
        #[LiteralTypeScriptType("'stored' | 'refused'")]
        /** @var 'stored'|'refused' */
        public string $outcome,
        public ?string $reason,
        /** @var list<string> sections the game reported as unreadable */
        public array $sectionErrors,
        /** ISO 8601 time of reception. */
        public string $receivedAt,
    ) {}

    public static function fromModel(UplinkMessage $message): self
    {
        return new self(
            id: $message->id,
            event: $message->event,
            colonyGuid: $message->colony_guid,
            colonyName: $message->colony_name,
            cycle: $message->cycle,
            size: $message->size,
            outcome: $message->outcome,
            reason: $message->reason,
            sectionErrors: array_values($message->section_errors ?? []),
            receivedAt: $message->received_at->toIso8601String(),
        );
    }
}
