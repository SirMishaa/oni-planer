<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Database\Factories\CodexEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Translatable\HasTranslations;

#[GameData]
#[Fillable(['codex_id', 'category', 'parent_codex_id', 'title', 'icon_asset', 'content', 'dlc_id'])]
final class CodexEntry extends Model
{
    /** @use HasFactory<CodexEntryFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title'];

    /** @return MorphToMany<Building, $this> */
    public function buildings(): MorphToMany
    {
        return $this->morphedByMany(Building::class, 'codexable');
    }

    /** @return MorphToMany<Element, $this> */
    public function elements(): MorphToMany
    {
        return $this->morphedByMany(Element::class, 'codexable');
    }

    /** @return MorphToMany<Critter, $this> */
    public function critters(): MorphToMany
    {
        return $this->morphedByMany(Critter::class, 'codexable');
    }

    /** @return MorphToMany<Plant, $this> */
    public function plants(): MorphToMany
    {
        return $this->morphedByMany(Plant::class, 'codexable');
    }

    /** @return MorphToMany<GeyserType, $this> */
    public function geyserTypes(): MorphToMany
    {
        return $this->morphedByMany(GeyserType::class, 'codexable');
    }

    public function casts(): array
    {
        return ['title' => 'array', 'content' => 'array'];
    }
}
