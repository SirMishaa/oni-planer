<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

final class RocketModule extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['module_id', 'module_type', 'mass', 'capacity', 'power_consumption', 'name', 'dlc_id'];

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
