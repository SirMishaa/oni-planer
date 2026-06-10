<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ElementRefData extends Data
{
    public function __construct(
        public string $element_id,
        public string $name,
        public ?string $icon,
        public ?string $url,
    ) {}
}
