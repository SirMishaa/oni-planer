<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'building_id', 'category', 'power_consumption', 'power_generation',
    'heat_generation', 'width', 'height', 'construction_time',
    'tags', 'name', 'description', 'dlc_id',
])]
final class Building extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasMany<BuildingConstructionMaterial, $this> */
    public function constructionMaterials(): HasMany
    {
        return $this->hasMany(BuildingConstructionMaterial::class);
    }

    public function casts(): array
    {
        return ['tags' => 'array', 'name' => 'array', 'description' => 'array'];
    }
}
