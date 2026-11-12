<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Everything the zone map draws. Each layer is null when the game could not
 * read it (or it was not received yet): unknown, which is not the same as an
 * empty list (nothing there).
 */
final class ZoneMapData extends Data
{
    public function __construct(
        public int $gridWidth,
        /** Cells drawn: the zone and a margin around it. */
        public ZoneBoxData $box,
        /** @var list<array{0: int, 1: int}> the zone's own cells as [x, y] */
        #[LiteralTypeScriptType('Array<[number, number]>')]
        public array $zoneCells,
        /** @var list<ZoneTerrainCellData>|null */
        public ?array $terrain,
        /** @var list<ZoneTileData>|null */
        public ?array $tiles,
        /** @var list<ZoneBuildingData>|null */
        public ?array $buildings,
        /** @var list<ZonePlantData>|null */
        public ?array $plants,
    ) {}
}
