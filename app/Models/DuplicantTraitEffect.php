<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DuplicantTraitEffect extends Model
{
    protected $fillable = ['duplicant_trait_id', 'stat', 'modifier', 'modifier_type'];
}
