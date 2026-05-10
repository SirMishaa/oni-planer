<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plant_mutation_id', 'stat', 'modifier', 'modifier_type'])]
final class PlantMutationEffect extends Model {}
