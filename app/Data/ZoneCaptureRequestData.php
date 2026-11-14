<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** The latest capture the site asked the mod for, for this zone. */
final class ZoneCaptureRequestData extends Data
{
    public function __construct(
        /** `pending`, `done` (the mod accepted it), `refused` (refused or failed, see reason) or `expired`. */
        public string $status,
        public ?string $reason,
        /** ISO-8601 time it was asked. */
        public string $issuedAt,
    ) {}
}
