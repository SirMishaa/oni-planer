<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Database\Factories\PrefabNameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Localization key of any game prefab id (element, critter morph, egg, plant,
 * food item...). The display name is `__($name_key)` from the game catalogs.
 */
#[GameData]
#[Fillable(['prefab_id', 'name_key'])]
final class PrefabName extends Model
{
    /** @use HasFactory<PrefabNameFactory> */
    use HasFactory;
}
