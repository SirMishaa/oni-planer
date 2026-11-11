<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Where the Uplink channel stands for the colony pages. Live vs stale is
 * decided in the browser from `lastSeenAt`.
 */
final class ColonyStatusData extends Data
{
    public function __construct(
        #[LiteralTypeScriptType("'never' | 'no_colony' | 'ok' | 'newer_schema'")]
        /** @var 'never'|'no_colony'|'ok'|'newer_schema' */
        public string $state,
        public ?string $colonyName,
        public ?int $cycle,
        /** ISO 8601 time of the last stored state. */
        public ?string $lastSeenAt,
        public ?int $schema,
        #[LiteralTypeScriptType("{ channel: 'uplink'; event: '.client-colony.state' }")]
        /** @var array{channel: 'uplink', event: '.client-colony.state'} Echo subscription of the live updates */
        public array $echo = ['channel' => 'uplink', 'event' => '.client-colony.state'],
    ) {}
}
