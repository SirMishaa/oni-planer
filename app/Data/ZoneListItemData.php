<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** One row of the zones list. */
final class ZoneListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $worldId,
        public int $cellCount,
        public bool $orphaned,
        /** Entities with at least one problem; null until a fast state arrived. */
        public ?int $problemCount,
        /** ISO 8601 time of the latest layout or state received, null when none was. */
        public ?string $updatedAt,
    ) {}
}
