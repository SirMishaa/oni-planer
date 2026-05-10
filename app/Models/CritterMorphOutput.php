<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CritterMorphOutput extends Model
{
    protected $fillable = ['critter_morph_id', 'element_id', 'amount_per_cycle', 'output_type'];

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }
}
