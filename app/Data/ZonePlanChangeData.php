<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** One change of a zone plan, placed on the map by its footprint after rotation. */
final class ZonePlanChangeData extends Data
{
    public function __construct(
        public int $id,
        /** add, remove or replace. */
        public string $kind,
        /** pending, applied or conflicted. */
        public string $state,
        /** Whether the change is on a layer the mod sends (validated and applied) or only drawn. */
        public bool $checked,
        public bool $movedByPlayer,
        public string $note,
        /** The building the change adds; null for a removal. */
        public ?string $prefabId,
        /** Display name of the added building in the current locale. */
        public ?string $name,
        /** Bottom-left cell of the added footprint, after rotation. */
        public ?int $x,
        public ?int $y,
        public ?int $width,
        public ?int $height,
        public string $orientation,
        /** @var array{x: int, y: int, width: int, height: int, label: string}|null the building or tile a removal or replacement takes out */
        public ?array $target,
        /** Whether the player can drag it: adds only, never removals or replacements. */
        public bool $movable,
    ) {}
}
