<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

final class RocketEngine extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'engine_id', 'fuel_element_id', 'oxidizer_element_id', 'max_range',
        'fuel_consumption_rate', 'oxidizer_consumption_rate', 'exhaust_temperature', 'name', 'dlc_id',
    ];

    public function fuelElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'fuel_element_id', 'element_id');
    }

    public function oxidizerElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'oxidizer_element_id', 'element_id');
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
