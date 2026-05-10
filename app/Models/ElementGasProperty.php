<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'element_id', 'flow', 'default_pressure', 'gas_surface_area_multiplier',
    'is_breathable', 'is_toxic',
])]
final class ElementGasProperty extends Model
{
    public $incrementing = false;

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
            'is_breathable' => 'boolean',
            'is_toxic' => 'boolean',
        ];
    }
}
