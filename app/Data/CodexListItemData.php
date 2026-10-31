<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CodexListItemData extends Data
{
    public function __construct(
        public string $codex_id,
        public string $category,
        public ?string $parent_codex_id,
        public string $title,
        public ?string $image,
    ) {}
}
