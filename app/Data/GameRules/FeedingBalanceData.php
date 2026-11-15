<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** How long the stock of accepted food lasts for a group of consumers. */
final class FeedingBalanceData extends Data
{
    /**
     * @param  list<AcceptedFoodData>  $accepts
     */
    public function __construct(
        public string $groupKey,
        public array $accepts,
        /** Kg in stock of every accepted food. */
        public float $availableKg,
        /** The accepted food with the most stock, null without stock. */
        public ?string $mainResource,
        /** The need per cycle when nothing accepted is in stock, otherwise null. */
        public ?float $deficitKgPerCycle,
        /** Cycles the stock lasts at the main food's rate; null when the need is zero. */
        public ?float $autonomyCycles,
        public bool $derived = true,
    ) {}
}
