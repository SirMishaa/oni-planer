<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

#[Fillable(['plant_id', 'name', 'dlc_id'])]
final class Plant extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @return HasMany<PlantVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(PlantVariant::class);
    }

    /** @return HasOne<PlantVariant, $this> */
    public function baseVariant(): HasOne
    {
        return $this->hasOne(PlantVariant::class)->where('is_base', true);
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
