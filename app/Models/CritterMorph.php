<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class CritterMorph extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'morph_id', 'critter_id', 'is_base', 'min_temp', 'max_temp',
        'calories_per_cycle', 'incubation_time', 'lifespan',
        'overcrowding_threshold', 'name',
    ];

    public function critter(): BelongsTo
    {
        return $this->belongsTo(Critter::class);
    }

    public function diets(): HasMany
    {
        return $this->hasMany(CritterMorphDiet::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(CritterMorphOutput::class);
    }

    public function casts(): array
    {
        return ['is_base' => 'boolean', 'name' => 'array'];
    }
}
