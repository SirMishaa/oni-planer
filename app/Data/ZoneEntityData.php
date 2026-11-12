<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** One plant or building of the zone with its state, for the entities tab and the problems. */
final class ZoneEntityData extends Data
{
    public function __construct(
        #[LiteralTypeScriptType("'plant' | 'building'")]
        /** @var 'plant'|'building' */
        public string $kind,
        public ?string $instanceId,
        public string $prefabId,
        public string $name,
        public ?string $sprite,
        /** Cell position; null when the layout does not place it. */
        public ?int $x,
        public ?int $y,
        #[LiteralTypeScriptType("'growing' | 'grown' | 'wilting' | 'operational' | 'not_operational'")]
        /** @var 'growing'|'grown'|'wilting'|'operational'|'not_operational' */
        public string $state,
        /** @var list<string> display text of each problem, in the current locale */
        public array $problems,
        /** Plants only: progress to the next harvest, 0 to 1. */
        public ?float $growth = null,
        public ?float $secondsUntilHarvest = null,
        public ?bool $autoHarvest = null,
        /** Sprite of the element a problem names (`Insufficient Resources: Fertilizer`), when one is recognised. */
        public ?string $neededItemSprite = null,
    ) {}
}
