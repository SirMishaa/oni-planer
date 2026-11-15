<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * A resource that a source consumes or produces every cycle. Shared by every source kind, so a colony
 * balance can add up critters, plants and buildings.
 *
 * A consumed flow with alternatives lists them in `accepts`: `resource` and `perCycle` then name the
 * first one.
 */
final class ResourceFlowData extends Data
{
    /**
     * @param  'consumed'|'produced'  $direction
     * @param  'critter_group'|'plant'|'building'  $sourceKind
     * @param  list<AcceptedFoodData>  $accepts
     */
    public function __construct(
        public string $resource,
        /** Amount per cycle in the resource's own unit: kg for an element, a count for an egg. */
        public float $perCycle,
        #[LiteralTypeScriptType("'consumed' | 'produced'")]
        public string $direction,
        #[LiteralTypeScriptType("'critter_group' | 'plant' | 'building'")]
        public string $sourceKind,
        public string $sourceId,
        public ?int $zoneId,
        public array $accepts = [],
    ) {}
}
