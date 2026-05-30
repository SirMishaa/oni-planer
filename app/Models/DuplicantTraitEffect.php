<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[GameData]
#[Fillable(['duplicant_trait_id', 'stat', 'modifier', 'modifier_type'])]
final class DuplicantTraitEffect extends Model
{
    use HasFactory;
}
