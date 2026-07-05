<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CodexLinkData extends Data
{
    public function __construct(
        public string $type,
        public string $key,
        public string $label,
        public ?string $url,
    ) {}
}
