<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A critter of the zone, from the fast state. Values the game did not expose are null. */
final class ZoneCritterData extends Data
{
    /**
     * @param  'left'|'right'  $facing
     * @param  list<array{cell: int, navType: string}>  $path
     * @param  list<array{egg: string, weight: float}>  $breeding
     * @param  list<array{food: string, calories: float}>  $stomach
     * @param  list<array{id: string, name: string, bad: bool}>  $statuses
     */
    public function __construct(
        public string $instanceId,
        public string $prefabId,
        public string $name,
        public ?string $sprite,
        public int $cell,
        public float $x,
        public float $y,
        public string $facing,
        public string $navType,
        public bool $moving,
        public float $speed,
        public array $path,
        public bool $aquatic,
        public bool $tame,
        public bool $baby,
        public ?float $ageCycles,
        public ?float $lifespanCycles,
        /** Calories over the stomach capacity, 0 to 1. */
        public ?float $caloriesRatio,
        /** Fertility over its maximum, 0 to 1. */
        public ?float $fertilityRatio,
        public array $breeding,
        public ?int $happiness,
        public ?string $happinessState,
        public array $stomach,
        public ?string $nextPoop,
        public bool $hungry,
        public array $statuses,
        public ?string $regionId,
    ) {}
}
