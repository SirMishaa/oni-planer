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
        public ZoneCountsData $counts,
        /** False until a fast state carried the plants: plant entities are then unknown, not absent. */
        public bool $plantsKnown,
        /** False until a fast state carried the building states: building entities and their problems are then unknown. */
        public bool $buildingStatesKnown,
        /** @var array{channel: 'uplink', event: '.zone.updated'} Echo subscription of the live updates */
        #[LiteralTypeScriptType("{ channel: 'uplink'; event: '.zone.updated' }")]
        public array $echo = ['channel' => 'uplink', 'event' => '.zone.updated'],
    ) {}
}
