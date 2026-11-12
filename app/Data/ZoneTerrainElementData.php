<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SubstanceTexture;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** One entry of the terrain palette. */
final class ZoneTerrainElementData extends Data
{
    public function __construct(
        /** Null when the game sent no element id for these cells. */
        public ?string $elementId,
        /** `#rrggbb` ui colour of the element; null when unknown to the game data. */
        public ?string $colour,
        /** Display name in the current locale; null when unknown to the game data. */
        public ?string $name = null,
        #[LiteralTypeScriptType("'solid' | 'liquid' | 'gas' | 'special' | null")]
        /** @var 'solid'|'liquid'|'gas'|'special'|null */
        public ?string $state = null,
        /** `#rrggbbaa` colour the game renders the element with; null when unknown. */
        public ?string $renderColour = null,
        public ?SubstanceTexture $substanceTexture = null,
        public bool $usesCaustics = false,
        public bool $glows = false,
        public bool $metallic = false,
        public bool $opaqueLiquid = false,
        /** Scroll speed of a liquid's texture. */
        public ?float $scrollSpeed = null,
        /** Kilograms a cell of this element holds at most. */
        public ?float $maxMass = null,
        /** URL of the terrain texture of a solid; null when there is none or it was not imported. */
        public ?string $terrainTexture = null,
        /** Kelvin below which the element changes state; null when it never does. */
        public ?float $lowTemp = null,
        /** Kelvin above which the element changes state; null when it never does. */
        public ?float $highTemp = null,
    ) {}
}
