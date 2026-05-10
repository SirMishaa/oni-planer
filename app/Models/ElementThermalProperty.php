<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'element_id', 'specific_heat_capacity', 'thermal_conductivity',
    'low_temp', 'high_temp', 'default_temperature',
    'light_absorption_factor', 'radiation_absorption_factor', 'radiation_per_1000_mass',
])]
final class ElementThermalProperty extends Model
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
            'low_temp' => 'float',
            'high_temp' => 'float',
        ];
    }
}
