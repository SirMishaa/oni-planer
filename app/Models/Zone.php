<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of cells defined in the game and sent by the mod, one row per
 * colony and zone guid. The colony guid is a plain string (no foreign key):
 * colonies key on their guid and may be re-created. User data, not game data:
 * it is never truncated by `game:import`.
 */
#[Fillable([
    'colony_guid', 'zone_guid', 'name', 'world_id', 'version', 'cell_count', 'orphaned', 'archived_at',
    'layout', 'slow_state', 'fast_state', 'layout_at', 'slow_state_at', 'fast_state_at', 'previous_slow_temperature',
])]
final class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    protected $table = 'zones';

    public function casts(): array
    {
        return [
            'world_id' => 'integer',
            'version' => 'integer',
            'cell_count' => 'integer',
            'orphaned' => 'boolean',
            'archived_at' => 'datetime',
            'layout' => 'array',
            'slow_state' => 'array',
            'previous_slow_temperature' => 'array',
            'fast_state' => 'array',
            'layout_at' => 'datetime',
            'slow_state_at' => 'datetime',
            'fast_state_at' => 'datetime',
        ];
    }

    /** @return HasMany<ZoneVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ZoneVersion::class);
    }

    /**
     * Zones that were not archived.
     *
     * @param  Builder<Zone>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
