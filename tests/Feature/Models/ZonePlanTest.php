<?php

declare(strict_types=1);

use App\Enums\ZonePlanChangeKind;
use App\Enums\ZonePlanChangeState;
use App\Models\Zone;
use App\Models\ZonePlan;
use App\Models\ZonePlanChange;
use Illuminate\Database\UniqueConstraintViolationException;

it('deletes the plan and its changes with the zone', function (): void {
    $zone = Zone::factory()->create();
    $plan = ZonePlan::factory()->for($zone)->create();
    ZonePlanChange::factory()->for($plan, 'plan')->count(2)->create();

    $zone->delete();

    expect(ZonePlan::query()->count())->toBe(0)
        ->and(ZonePlanChange::query()->count())->toBe(0);
});

it('keeps one plan per zone', function (): void {
    $zone = Zone::factory()->create();
    ZonePlan::factory()->for($zone)->create();

    ZonePlan::factory()->for($zone)->create();
})->throws(UniqueConstraintViolationException::class);

it('orders changes by position and says whether they add or remove', function (): void {
    $plan = ZonePlan::factory()->create();
    $second = ZonePlanChange::factory()->for($plan, 'plan')->remove()->create(['position' => 2]);
    $first = ZonePlanChange::factory()->for($plan, 'plan')->add()->create(['position' => 1]);
    $third = ZonePlanChange::factory()->for($plan, 'plan')->replace()->create(['position' => 3]);

    expect($plan->changes->modelKeys())->toBe([$first->id, $second->id, $third->id])
        ->and($first->adds())->toBeTrue()->and($first->removes())->toBeFalse()
        ->and($second->adds())->toBeFalse()->and($second->removes())->toBeTrue()
        ->and($third->adds())->toBeTrue()->and($third->removes())->toBeTrue()
        ->and($first->fresh()->state)->toBe(ZonePlanChangeState::Pending)
        ->and($first->kind)->toBe(ZonePlanChangeKind::Add)
        ->and($first->orientation)->toBe('Neutral')
        ->and($plan->zone->plan->is($plan))->toBeTrue();
});

it('leaves the changes the player deleted out of the plan changes', function (): void {
    $plan = ZonePlan::factory()->create();
    $kept = ZonePlanChange::factory()->for($plan, 'plan')->create();
    $deleted = ZonePlanChange::factory()->for($plan, 'plan')->create(['position' => 2, 'deleted_by_player' => true]);

    expect($plan->changes()->pluck('id')->all())->toBe([$kept->id])
        ->and($plan->deletedChanges()->pluck('id')->all())->toBe([$deleted->id])
        ->and($kept->fresh()->deleted_by_player)->toBeFalse();
});

it('touches the plan when a change is saved', function (): void {
    $plan = ZonePlan::factory()->create();
    $change = ZonePlanChange::factory()->for($plan, 'plan')->create();
    $plan->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $change->update(['note' => 'Moved']);

    expect($plan->fresh()->updated_at->greaterThan(now()->subMinute()))->toBeTrue();
});
