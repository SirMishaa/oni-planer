<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/** The proposed variant of a zone: its changes in order. */
final class ZonePlanData extends Data
{
    public function __construct(
        /** ISO-8601 time the plan last changed. */
        public string $updatedAt,
        /** @var list<ZonePlanChangeData> */
        public array $changes,
    ) {}
}
