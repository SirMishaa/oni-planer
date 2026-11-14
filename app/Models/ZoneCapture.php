<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZoneCaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture of a zone rendered by the game (Uplink `client-zone.capture`), stored on the public
 * disk at `path`. The last {@see self::KEEP} of each zone are kept. User data: never truncated
 * by `game:import`.
 *
 * @property-read int $cycle
 */
#[Fillable(['zone_id', 'path', 'taken_at', 'game_seconds', 'layout_signature', 'trigger', 'command_id', 'width', 'height'])]
final class ZoneCapture extends Model
{
    /** @use HasFactory<ZoneCaptureFactory> */
    use HasFactory;

    /** Captures kept per zone; storing one more deletes the oldest (row and file). */
    public const int KEEP = 15;

    /** Game seconds in a cycle. */
    private const int CYCLE_SECONDS = 600;

    public function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'game_seconds' => 'float',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return Attribute<int, never> the game cycle the capture was taken in */
    protected function cycle(): Attribute
    {
        return Attribute::get(fn (): int => intdiv((int) $this->game_seconds, self::CYCLE_SECONDS));
    }
}
