<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** One food a flock group eats, or one thing it produces, for the whole group per cycle. */
final class ZoneFeedingLineData extends Data
{
    /**
     * @param  'item'|'plant'|'prey'|'dropping'|'egg'  $kind
     */
    public function __construct(
        public string $resource,
        public string $name,
        /**
         * Per cycle for the whole group: kg for an `item` or a `dropping`, eggs for an `egg`; for a `plant`
         * (growth eaten) or `prey` (the game's own amount) it is not kg.
         */
        public float $perCycle,
        /** An `item` picked up (stockable), a `plant` eaten in place, `prey`, a `dropping` or an `egg`. */
        #[LiteralTypeScriptType("'item' | 'plant' | 'prey' | 'dropping' | 'egg'")]
        public string $kind,
        /** Matching plants or prey critters in the zone, for a `plant` or `prey` line; null otherwise or when unread. */
        public ?int $inZone = null,
    ) {}
}
