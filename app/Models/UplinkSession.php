<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UplinkSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One game session (a save load) of a colony, announced by the mod over the
 * Uplink data channel. User data, not game data: it is never truncated by
 * `game:import`.
 */
#[Fillable(['colony_guid', 'session_id', 'loaded_game_seconds', 'cycle', 'started_at'])]
final class UplinkSession extends Model
{
    /** @use HasFactory<UplinkSessionFactory> */
    use HasFactory;

    protected $table = 'uplink_sessions';

    public function casts(): array
    {
        return [
            'loaded_game_seconds' => 'float',
            'cycle' => 'integer',
            'started_at' => 'datetime',
        ];
    }
}
