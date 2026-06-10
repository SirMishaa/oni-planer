<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class RanchCatalogData extends Data
{
    public function __construct(
        /** @var list<CritterFamilyData> */
        public array $families,
        /** @var array<string, ElementRefData> */
        public array $elements,
    ) {}
}
