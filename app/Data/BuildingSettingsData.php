<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** What the player set on a building: its filter and its capacity, from the fast state. */
final class BuildingSettingsData extends Data
{
    /**
     * @param  list<string>|null  $filter  accepted tag names; null when the building has no filter, empty when nothing is ticked
     */
    public function __construct(
        public ?array $filter,
        public ?BuildingCapacityData $capacity,
    ) {}
}
