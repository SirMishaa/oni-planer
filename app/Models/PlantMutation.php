<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable(['mutation_id', 'name'])]
final class PlantMutation extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @return HasMany<PlantMutationEffect, $this> */
    public function effects(): HasMany
    {
        return $this->hasMany(PlantMutationEffect::class);
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
