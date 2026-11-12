<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The terrain of the slow state, columnar like the mod's slow message: each
 * array holds one value per cell of `box`, row-major from its bottom-left
 * cell (y ascending, then x ascending). A layer is null when the game could
 * not read it.
 */
final class ZoneTerrainData extends Data
{
    public function __construct(
        /** The cells the arrays cover; may differ from the map box when the zone changed since. */
        public ZoneBoxData $box,
        /** @var list<ZoneTerrainElementData> elements the `element` indices point to */
        public array $palette,
        /** @var list<int>|null index into `palette` per cell, -1 when unknown */
        public ?array $element,
        /** @var list<float|null>|null kilograms per cell, null when not finite */
        public ?array $mass,
        /** @var list<float|null>|null kelvin per cell, null when not finite */
        public ?array $temperature,
        /** @var list<float|null>|null kelvin per cell of the previous slow reading; null when none, or its box or length differs */
        public ?array $previousTemperature = null,
        /** Seconds between the previous and the current reading. */
        public ?float $previousSeconds = null,
    ) {}
}
