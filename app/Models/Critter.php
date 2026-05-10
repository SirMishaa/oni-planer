<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

final class Critter extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['critter_id', 'name', 'dlc_id'];

    public function morphs(): HasMany
    {
        return $this->hasMany(CritterMorph::class);
    }

    public function baseMorph(): HasOne
    {
        return $this->hasOne(CritterMorph::class)->where('is_base', true);
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
