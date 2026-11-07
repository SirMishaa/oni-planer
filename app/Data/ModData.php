<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Mod;
use Spatie\LaravelData\Data;

final class ModData extends Data
{
    public function __construct(
        public string $workshop_id,
        public string $title,
    ) {}

    public static function fromModel(?Mod $mod): ?self
    {
        return $mod instanceof Mod ? new self($mod->workshop_id, $mod->title) : null;
    }
}
