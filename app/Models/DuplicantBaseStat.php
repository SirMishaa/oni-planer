<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DuplicantBaseStat extends Model
{
    protected $fillable = [
        'oxygen_consumption_gs', 'co2_production_gs', 'calories_per_cycle', 'mass_kg', 'bladder_fill_per_cycle',
    ];
}
