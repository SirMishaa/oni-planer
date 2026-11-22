<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use Database\Factories\ZonePlanChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a zone plan. Adds and replaces carry `prefab_id`, `anchor_cell` and `orientation`;
 * removes and replaces target an existing building by `target_instance_id` or `target_cell`.
 * Changes whose `object_layer` is not checked (`checked = false`) are drawn but never validated or applied.
 */
#[Fillable([
    'zone_plan_id', 'position', 'kind', 'state', 'prefab_id', 'anchor_cell', 'orientation', 'object_layer',
    'checked', 'target_instance_id', 'target_cell', 'note', 'moved_by_player',
])]
final class ZonePlanChange extends Model
{
    /** @use HasFactory<ZonePlanChangeFactory> */
    use HasFactory;

    public function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => ZonePlanChangeKind::class,
            'state' => ZonePlanChangeState::class,
            'anchor_cell' => 'integer',
            'checked' => 'boolean',
            'target_cell' => 'integer',
            'moved_by_player' => 'boolean',
        ];
    }

    /** @return BelongsTo<ZonePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ZonePlan::class, 'zone_plan_id');
    }

    /** Whether the change puts a building in the layout (add or replace). */
    public function adds(): bool
    {
        return $this->kind === ZonePlanChangeKind::Add || $this->kind === ZonePlanChangeKind::Replace;
    }

    /** Whether the change takes a building out of the layout (remove or replace). */
    public function removes(): bool
    {
        return $this->kind === ZonePlanChangeKind::Remove || $this->kind === ZonePlanChangeKind::Replace;
    }
}
