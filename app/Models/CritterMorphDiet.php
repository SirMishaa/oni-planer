<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CritterMorphDiet extends Model
{
    protected $fillable = [
        'critter_morph_id', 'consumed_element_id', 'amount_per_cycle',
        'produced_element_id', 'conversion_ratio',
    ];

    public function consumedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'consumed_element_id', 'element_id');
    }

    public function producedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'produced_element_id', 'element_id');
    }
}
