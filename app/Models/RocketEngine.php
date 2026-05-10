<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'engine_id', 'fuel_element_id', 'oxidizer_element_id', 'max_range',
    'fuel_consumption_rate', 'oxidizer_consumption_rate', 'exhaust_temperature', 'name', 'dlc_id',
])]
final class RocketEngine extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @return BelongsTo<Element, $this> */
    public function fuelElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'fuel_element_id', 'element_id');
    }

    /** @return BelongsTo<Element, $this> */
    public function oxidizerElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'oxidizer_element_id', 'element_id');
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
