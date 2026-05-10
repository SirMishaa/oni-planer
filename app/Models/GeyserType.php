<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

final class GeyserType extends Model
{
    use HasFactory, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'geyser_id', 'type', 'element_id', 'temperature', 'max_pressure',
        'min_yield_rate', 'max_yield_rate', 'min_eruption_duration', 'max_eruption_duration',
        'min_eruption_period', 'max_eruption_period', 'dormancy_min_cycles', 'dormancy_max_cycles',
        'name', 'dlc_id',
    ];

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }

    public function casts(): array
    {
        return ['name' => 'array'];
    }
}
