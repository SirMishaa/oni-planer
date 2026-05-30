<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[GameData]
#[Fillable([
    'element_id', 'solid_surface_area_multiplier', 'hardness',
    'is_ore', 'is_metal', 'is_refined_metal',
])]
#[WithoutIncrementing]
final class ElementSolidProperty extends Model
{
    use HasFactory;
    use HasFactory;

    protected $primaryKey = 'element_id';

    protected $keyType = 'string';

    /** @return BelongsTo<Element, $this> */
    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }

    public function casts(): array
    {
        return [
            'is_ore' => 'boolean',
            'is_metal' => 'boolean',
            'is_refined_metal' => 'boolean',
        ];
    }
}
