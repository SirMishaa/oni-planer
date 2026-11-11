<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Database\Factories\FoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An edible item and its calories per unit (in cal). The display name is the
 * {@see PrefabName} of `food_id`.
 */
#[GameData]
#[Fillable(['food_id', 'calories_per_unit', 'quality', 'can_rot', 'dlc_id'])]
final class Food extends Model
{
    /** @use HasFactory<FoodFactory> */
    use HasFactory;

    /**
     * "food" is uncountable for the inflector. A property rather than the
     * #[Table] attribute, because GameDataTables reads the table of an
     * instance built without its constructor.
     */
    protected $table = 'foods';

    public function casts(): array
    {
        return [
            'calories_per_unit' => 'float',
            'quality' => 'integer',
            'can_rot' => 'boolean',
        ];
    }
}
