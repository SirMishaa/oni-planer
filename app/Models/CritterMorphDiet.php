<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'critter_morph_id', 'consumed_element_id', 'amount_per_cycle',
    'produced_element_id', 'conversion_ratio',
])]
final class CritterMorphDiet extends Model
{
    /** @return BelongsTo<Element, $this> */
    public function consumedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'consumed_element_id', 'element_id');
    }

    /** @return BelongsTo<Element, $this> */
    public function producedElement(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'produced_element_id', 'element_id');
    }
}
