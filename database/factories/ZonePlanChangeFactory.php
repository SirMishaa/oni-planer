<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ZonePlanChange> */
final class ZonePlanChangeFactory extends Factory
{
    protected $model = ZonePlanChange::class;

    public function definition(): array
    {
        return [
            'zone_plan_id' => ZonePlan::factory(),
            'position' => 1,
            'kind' => ZonePlanChangeKind::Add,
            'state' => ZonePlanChangeState::Pending,
            'prefab_id' => 'ManualGenerator',
            'anchor_cell' => 1000,
            'orientation' => 'Neutral',
            'object_layer' => 'Building',
            'checked' => true,
            'target_instance_id' => null,
            'target_cell' => null,
            'note' => 'Plan note',
            'moved_by_player' => false,
        ];
    }

    public function add(): self
    {
        return $this->state(['kind' => ZonePlanChangeKind::Add]);
    }

    public function remove(): self
    {
        return $this->state([
            'kind' => ZonePlanChangeKind::Remove,
            'prefab_id' => null,
            'anchor_cell' => null,
            'target_instance_id' => 'building-1',
            'target_cell' => 1000,
        ]);
    }

    public function replace(): self
    {
        return $this->state([
            'kind' => ZonePlanChangeKind::Replace,
            'target_instance_id' => 'building-1',
            'target_cell' => 1000,
        ]);
    }
}
