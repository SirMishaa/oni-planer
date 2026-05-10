<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'oxygen_consumption_gs', 'co2_production_gs', 'calories_per_cycle', 'mass_kg', 'bladder_fill_per_cycle',
])]
final class DuplicantBaseStat extends Model {}
