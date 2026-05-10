<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class Building extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'building_id', 'category', 'power_consumption', 'power_generation',
        'heat_generation', 'width', 'height', 'construction_time',
        'tags', 'name', 'description', 'dlc_id',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function constructionMaterials(): HasMany
    {
        return $this->hasMany(BuildingConstructionMaterial::class);
    }

    public function casts(): array
    {
        return ['tags' => 'array', 'name' => 'array', 'description' => 'array'];
    }
}
