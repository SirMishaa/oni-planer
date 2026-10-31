<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        /** Official family label in the current locale (e.g. "Gloutons"). */
        public ?string $plural_name,
        public ?string $description,
        public ?string $image,
        /** @var list<CritterVariantData> */
        public array $variants,
    ) {}
}
