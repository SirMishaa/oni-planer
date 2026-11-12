<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZoneVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cell set of a zone at a point in game time (sorted list of cell
 * indices). A new version exists only when the cell set changed. User data,
 * not game data: it is never truncated by `game:import`.
 */
#[Fillable(['zone_id', 'version', 'cells', 'valid_from_game_seconds'])]
final class ZoneVersion extends Model
{
    /** @use HasFactory<ZoneVersionFactory> */
    use HasFactory;

    protected $table = 'zone_versions';

    public function casts(): array
    {
        return [
            'version' => 'integer',
            'cells' => 'array',
            'valid_from_game_seconds' => 'float',
        ];
    }

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
