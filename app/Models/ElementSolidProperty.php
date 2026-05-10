<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ElementSolidProperty extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'element_id';

    protected $keyType = 'string';

    protected $fillable = [
        'element_id', 'solid_surface_area_multiplier', 'hardness',
        'is_ore', 'is_metal', 'is_refined_metal',
    ];

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
