<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\GameData;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A room type of the game (washroom, barracks, ...). `name_key` is a STRINGS
 * key resolved at display time; `requirements` keeps the export's constraints
 * structured (see {@see \App\Services\GameImport\OniExport\RoomTypeReader}).
 */
#[GameData]
#[Fillable(['room_type_id', 'category', 'name_key', 'requirements', 'size_min', 'size_max', 'bonus_effect', 'priority'])]
final class RoomType extends Model
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['requirements' => 'array', 'size_min' => 'integer', 'size_max' => 'integer', 'priority' => 'integer'];
    }
}
