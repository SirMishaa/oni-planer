<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ElementSpecialProperty extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'element_id';

    protected $keyType = 'string';

    protected $fillable = ['element_id'];

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }
}
