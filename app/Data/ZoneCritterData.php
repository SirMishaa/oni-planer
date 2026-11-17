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
     * @param  list<array{egg: string, name: string, weight: float}>  $breeding
     * @param  list<array{food: string, calories: float}>  $stomach
     * @param  list<array{id: string, name: string, bad: bool}>  $statuses
     */
    public function __construct(
        public string $instanceId,
        public string $prefabId,
        public string $name,
        /** The portrait (`critters/{prefab}.webp`): the codex, flock and sheet image, facing any way. */
        public ?string $sprite,
        /** The map sprite (`critters/{prefab}_world.webp`): frame 0 of the anim the game draws, facing right like the game's art. */
        public ?string $worldSprite,
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
