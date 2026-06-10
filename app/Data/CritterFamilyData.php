<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        /** @var array<string, string>|null */
        public ?array $description,
        public ?string $image,
        /** @var list<CritterVariantData> */
        public array $variants,
    ) {}
}
