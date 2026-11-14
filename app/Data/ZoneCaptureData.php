<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** A picture of the zone rendered by the game. */
final class ZoneCaptureData extends Data
{
    public function __construct(
        public int $id,
        /** Root-relative URL of the PNG. */
        public string $url,
        /** ISO-8601 time the site stored it. */
        public string $takenAt,
        /** Game cycle it was taken in. */
        public int $cycle,
        /** `manual` (asked from the site) or `auto` (after a building spree). */
        public string $trigger,
        /** Whether the zone's current layout on the site is the one the capture shows, so the map compares fairly. */
        public bool $matchesLayout,
        public int $width,
        public int $height,
    ) {}
}
