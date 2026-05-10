<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

#[Fillable(['module_id', 'module_type', 'mass', 'capacity', 'power_consumption', 'name', 'dlc_id'])]
final class RocketModule extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
