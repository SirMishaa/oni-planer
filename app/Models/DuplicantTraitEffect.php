<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['duplicant_trait_id', 'stat', 'modifier', 'modifier_type'])]
final class DuplicantTraitEffect extends Model {}
