<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CritterFamilyListItemData extends Data
{
    public function __construct(
        public string $slug,
        public string $name,
        /** Official family label in the current locale (e.g. "Gloutons"). */
        public ?string $plural_name,
        public ?string $image,
        public ?string $image_idle,
        public int $variant_count,
        public ?string $eats_summary,
        public ?string $makes_summary,
        public ?string $dlc_id,
        /** @var list<CritterVariantPreviewData> */
        public array $variant_previews,
        public float $min_temp,
        public float $max_temp,
        public ?ModData $mod = null,
    ) {}
}
