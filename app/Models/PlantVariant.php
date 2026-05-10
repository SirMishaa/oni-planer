<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'variant_id', 'plant_id', 'is_base', 'min_temp', 'max_temp',
    'min_pressure', 'max_pressure', 'atmosphere_element_id',
    'light_required', 'growth_time', 'name',
])]
final class PlantVariant extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @return BelongsTo<Plant, $this> */
    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    /** @return HasMany<PlantVariantInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(PlantVariantInput::class);
    }

    /** @return HasMany<PlantVariantOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(PlantVariantOutput::class);
    }

    /** @return BelongsToMany<PlantMutation, $this> */
    public function mutations(): BelongsToMany
    {
        return $this->belongsToMany(PlantMutation::class, 'plant_variant_mutations');
    }

    public function casts(): array
    {
        return ['is_base' => 'boolean', 'light_required' => 'boolean', 'name' => 'array'];
    }
}
