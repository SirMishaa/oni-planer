<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ColonyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The last colony state received over the Uplink channel, one row per colony
 * (keyed by the save's colony guid). User data, not game data: it is never
 * truncated by `game:import`.
 */
#[Fillable(['guid', 'name', 'cycle', 'state', 'schema', 'last_seen_at'])]
final class Colony extends Model
{
    /** @use HasFactory<ColonyFactory> */
    use HasFactory;

    /**
     * "colony" is pluralised correctly by the inflector, but the name is set
     * explicitly so the table never depends on it.
     */
    protected $table = 'colonies';

    public function casts(): array
    {
        return [
            'cycle' => 'integer',
            'state' => 'array',
            'schema' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
