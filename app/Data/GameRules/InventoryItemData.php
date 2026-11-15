<?php

declare(strict_types=1);

namespace App\Data\GameRules;

use Spatie\LaravelData\Data;

/** A stock of one resource at one place (a storage, a floor pile, a building). */
final class InventoryItemData extends Data
{
    public function __construct(
        public string $resource,
        public float $kg,
        public string $placeKind,
        public string $placeId,
        public ?int $zoneId,
    ) {}
}
