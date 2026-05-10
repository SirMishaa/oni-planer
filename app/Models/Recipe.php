<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable(['building_id', 'duration', 'fabricators', 'name'])]
final class Recipe extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return HasMany<RecipeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** @return HasMany<RecipeItem, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->where('role', 'input');
    }

    /** @return HasMany<RecipeItem, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->where('role', 'output');
    }

    public function casts(): array
    {
        return ['fabricators' => 'array', 'name' => 'array'];
    }
}
