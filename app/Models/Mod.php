<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Database\Factories\ModFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A supported game mod (Steam workshop item) whose content is imported. */
#[GameData]
#[Fillable(['workshop_id', 'static_id', 'title'])]
final class Mod extends Model
{
    /** @use HasFactory<ModFactory> */
    use HasFactory;
}
