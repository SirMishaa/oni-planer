<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZonePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The proposed variant of a zone: one plan per zone, made of ordered changes. User data: never
 * truncated by `game:import`.
 */
#[Fillable(['zone_id'])]
final class ZonePlan extends Model
{
    /** @use HasFactory<ZonePlanFactory> */
    use HasFactory;

    /** @return BelongsTo<Zone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return HasMany<ZonePlanChange, $this> the changes of the plan, ordered by position; those the player deleted are left out */
    public function changes(): HasMany
    {
        return $this->hasMany(ZonePlanChange::class)->where('deleted_by_player', false)->orderBy('position');
    }

    /** @return HasMany<ZonePlanChange, $this> the changes the player deleted, ordered by position */
    public function deletedChanges(): HasMany
    {
        return $this->hasMany(ZonePlanChange::class)->where('deleted_by_player', true)->orderBy('position');
    }
}
