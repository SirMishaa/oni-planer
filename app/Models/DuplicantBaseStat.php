<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[GameData]
#[Fillable([
    'oxygen_consumption_gs', 'co2_production_gs', 'calories_per_cycle', 'mass_kg', 'bladder_fill_per_cycle',
])]
final class DuplicantBaseStat extends Model
{
    use HasFactory;
}
