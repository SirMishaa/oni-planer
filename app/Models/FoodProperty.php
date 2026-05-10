<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FoodProperty extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'element_id';

    protected $keyType = 'string';

    protected $fillable = ['element_id', 'calories', 'quality', 'can_rot'];

    public function element(): BelongsTo
    {
        return $this->belongsTo(Element::class, 'element_id', 'element_id');
    }

    public function casts(): array
    {
        return ['can_rot' => 'boolean'];
    }
}
