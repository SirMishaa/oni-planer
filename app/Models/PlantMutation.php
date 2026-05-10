<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class PlantMutation extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['mutation_id', 'name'];

    public function effects(): HasMany
    {
        return $this->hasMany(PlantMutationEffect::class);
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
