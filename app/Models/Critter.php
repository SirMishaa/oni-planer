<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use App\Models\Concerns\HasCodexEntries;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

#[GameData]
#[Fillable(['critter_id', 'variant_family', 'name', 'description', 'dlc_id'])]
final class Critter extends Model
{
    use HasCodexEntries;
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @return HasMany<CritterMorph, $this> */
    public function morphs(): HasMany
    {
        return $this->hasMany(CritterMorph::class);
    }

    /** @return HasOne<CritterMorph, $this> */
    public function baseMorph(): HasOne
    {
        return $this->hasOne(CritterMorph::class)->where('is_base', true);
    }

    public function casts(): array
    {
        return ['name' => 'array', 'description' => 'array'];
    }
}
