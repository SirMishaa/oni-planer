<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class PlantVariant extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'variant_id', 'plant_id', 'is_base', 'min_temp', 'max_temp',
        'min_pressure', 'max_pressure', 'atmosphere_element_id',
        'light_required', 'growth_time', 'name',
    ];

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(PlantVariantInput::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(PlantVariantOutput::class);
    }

    public function mutations(): BelongsToMany
    {
        return $this->belongsToMany(PlantMutation::class, 'plant_variant_mutations');
    }

    public function casts(): array
    {
        return ['is_base' => 'boolean', 'light_required' => 'boolean', 'name' => 'array'];
    }
}
