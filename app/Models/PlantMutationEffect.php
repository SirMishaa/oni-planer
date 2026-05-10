<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlantMutationEffect extends Model
{
    protected $fillable = ['plant_mutation_id', 'stat', 'modifier', 'modifier_type'];
}
