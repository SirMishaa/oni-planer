<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** The zone page. */
final class ZoneShowData extends Data
{
    public function __construct(
        public ZoneData $zone,
        /** Null until the zone's layout was received. */
        public ?ZoneMapData $map,
        /** @var list<ZoneEntityData> plants and buildings, those with a problem first, then by next harvest */
        public array $entities,
        /** @var list<ZoneEntityData> the entities with at least one problem */
        public array $problems,
        /** @var list<ZoneEntityData> passages (doors, ladders, stairs) on the zone or its border ring, never a problem */
        public array $borderEntities,
        public ZoneCountsData $counts,
        /** False until a fast state carried the plants: plant entities are then unknown, not absent. */
        public bool $plantsKnown,
        /** False until a fast state carried the building states: building entities and their problems are then unknown. */
        public bool $buildingStatesKnown,
        /** @var list<ZoneCaptureData> the game's pictures of the zone, newest first */
        public array $captures = [],
        /** The latest capture asked for this zone, null when none was. */
        public ?ZoneCaptureRequestData $captureRequest = null,
        /**
         * ISO-8601 time the link last stored this zone's colony state. The mod repeats it every
         * 15 s even while the game is paused (zone states then stop, being unchanged), so it says
         * whether the game is linked.
         */
        public ?string $linkSeenAt = null,
        /** @var array{channel: 'uplink', event: '.zone.updated'} Echo subscription of the live updates */
        #[LiteralTypeScriptType("{ channel: 'uplink'; event: '.zone.updated' }")]
        public array $echo = ['channel' => 'uplink', 'event' => '.zone.updated'],
    ) {}
}
