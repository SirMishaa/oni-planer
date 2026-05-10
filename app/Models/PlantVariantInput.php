<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plant_variant_id', 'element_id', 'amount_per_cycle', 'input_type'])]
final class PlantVariantInput extends Model
{
    /** @return BelongsTo<Element, $this> */
    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }
}
