<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\BuildingData;
use App\Models\Attributes\GameData;
use App\Models\Concerns\HasCodexEntries;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelData\Data;
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

    /**
     * The index is cached per locale since names are translated. The key also
     * carries a fingerprint of the {@see BuildingData} payload shape, so code
     * that changes the payload never reads entries cached by older code.
     */
    public static function indexCacheKey(string $locale): string
    {
        return self::INDEX_CACHE_KEY.'.'.mb_substr(hash('xxh128', self::dataShape(BuildingData::class)), 0, 12).'.'.$locale;
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

    /**
     * Public property names and types of a Data class, nested Data classes
     * included.
     *
     * @param  class-string  $dataClass
     */
    private static function dataShape(string $dataClass): string
    {
        $shape = [];

        foreach (new ReflectionClass($dataClass)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $typeName = (string) $type;
            $shape[] = $property->getName().':'.$typeName;

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), Data::class)) {
                $shape[] = '{'.self::dataShape($type->getName()).'}';
            }
        }

        return implode(',', $shape);
    }
}
