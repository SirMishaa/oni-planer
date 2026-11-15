<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Zones\ZonePresenter;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * The zone page. The props are grouped by the stream that changes them
 * ({@see ZonePresenter::PROPS_BY_KIND}): a live event reloads only its own.
 */
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
        /** @var list<ZonePopulationData>|null the crowding of each cavity and pond; null until the game sent the critters */
        public ?array $population = null,
        /** @var list<ZoneCritterData>|null the critters of the zone; null until the game sent them */
        public ?array $critters = null,
        /** @var list<ZoneEggData>|null the eggs of the zone, each with its total incubation; null until the game sent them */
        public ?array $eggs = null,
        /** Terrain of the slow state; null until the layout and a slow state with a readable layer arrived. */
        public ?ZoneTerrainData $mapTerrain = null,
        /** @var list<ZonePlantData>|null the plants of the fast state placed on the map; null until the layout and the plants arrived */
        public ?array $mapPlants = null,
        /** ISO-8601 time of the fast state the critters came from (their age label); null with no critters. */
        public ?string $crittersAt = null,
        /** @var array<string, list<string>> the props each `ZoneUpdated` kind changes, {@see ZonePresenter::PROPS_BY_KIND} */
        public array $liveProps = [],
        /** @var array{channel: 'uplink', event: '.zone.updated'} Echo subscription of the live updates */
        #[LiteralTypeScriptType("{ channel: 'uplink'; event: '.zone.updated' }")]
        public array $echo = ['channel' => 'uplink', 'event' => '.zone.updated'],
    ) {}
}
