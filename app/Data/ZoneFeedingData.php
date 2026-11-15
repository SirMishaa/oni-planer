<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\GameRules\FeedingBalanceData;
use App\Services\Zones\ZoneFeeding;
use Spatie\LaravelData\Data;

/**
 * What one flock band eats and produces, and how long the zone's stock of its accepted items lasts
 * ({@see FeedingBalanceData}), trimmed to what the band shows: a diet can list dozens of foods.
 */
final class ZoneFeedingData extends Data
{
    /**
     * @param  list<ZoneFeedingLineData>  $foods
     * @param  list<ZoneFeedingLineData>  $produces
     */
    public function __construct(
        /** The flock band: `${prefabId}:${stage}`. */
        public string $groupKey,
        public int $count,
        /**
         * The accepted foods shown: the item foods first, the main one (most stock) leading, then the plants and
         * prey, the most present in the zone first; at most {@see ZoneFeeding::SHOWN_FOODS} of each.
         */
        public array $foods,
        /** Accepted foods not listed in `foods`. */
        public int $otherFoods,
        /** The dropping of the main item food (else of the first food), then the eggs. */
        public array $produces,
        /** Kg in stock of every accepted item; null while the building storages were not read. */
        public ?float $availableKg,
        /** The need per cycle when no accepted item is in stock; null with stock, unknown stock or no item food. */
        public ?float $deficitKgPerCycle,
        /** Cycles the stock lasts; null with unknown stock or no item food. */
        public ?float $autonomyCycles,
        public bool $derived = true,
    ) {}
}
