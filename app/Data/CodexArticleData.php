<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class CodexArticleData extends Data
{
    public function __construct(
        public string $codex_id,
        public string $category,
        public ?string $parent_codex_id,
        public string $title,
        public ?string $icon_asset,
        /** @var array<int, mixed> */
        public array $content,
        public ?string $image,
        /** @var list<CodexLinkData> */
        public array $links,
    ) {}
}
