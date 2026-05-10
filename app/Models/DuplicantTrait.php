<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class DuplicantTrait extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['trait_id', 'is_positive', 'name', 'description', 'dlc_id'];

    public function effects(): HasMany
    {
        return $this->hasMany(DuplicantTraitEffect::class);
    }

    public function casts(): array
    {
        return ['is_positive' => 'boolean', 'name' => 'array', 'description' => 'array'];
    }
}
