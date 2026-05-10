<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

final class Plant extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['plant_id', 'name', 'dlc_id'];

    public function variants(): HasMany
    {
        return $this->hasMany(PlantVariant::class);
    }

    public function baseVariant(): HasOne
    {
        return $this->hasOne(PlantVariant::class)->where('is_base', true);
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
