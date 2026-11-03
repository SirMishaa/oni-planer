<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use App\Models\Concerns\HasCodexEntries;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[GameData]
#[Fillable([
    'building_id', 'category', 'power_consumption', 'power_generation',
    'heat_generation', 'width', 'height', 'construction_time',
    'tags', 'name', 'description', 'effect', 'dlc_id',
    'subcategory', 'ports', 'build_location_rule', 'permitted_rotations',
    'object_layer', 'scene_layer', 'overlay', 'is_foundation', 'is_tile',
    'is_utility', 'drag_build', 'replaceable', 'placement_rect', 'mod_id',
])]
final class Building extends Model
{
    use HasCodexEntries;

    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    use HasTranslations;

    public const string INDEX_CACHE_KEY = 'buildings.index';

    /** @var list<string> */
    public array $translatable = ['name', 'description', 'effect'];

    /** The index is cached per locale since names are translated. */
    public static function indexCacheKey(string $locale): string
    {
        return self::INDEX_CACHE_KEY.'.'.$locale;
    }

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

    /** @return BelongsTo<Mod, $this> */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class);
    }

    public function casts(): array
    {
        return [
            'tags' => 'array',
            'name' => 'array',
            'description' => 'array',
            'effect' => 'array',
            'ports' => 'array',
            'placement_rect' => 'array',
            'scene_layer' => 'integer',
            'is_foundation' => 'boolean',
            'is_tile' => 'boolean',
            'is_utility' => 'boolean',
            'drag_build' => 'boolean',
            'replaceable' => 'boolean',
        ];
    }
}
