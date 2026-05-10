<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class Recipe extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['building_id', 'duration', 'fabricators', 'name'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->where('role', 'input');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->where('role', 'output');
    }

    public function casts(): array
    {
        return ['fabricators' => 'array', 'name' => 'array'];
    }
}
