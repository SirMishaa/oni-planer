<?php

declare(strict_types=1);

use App\Models\Food;
use App\Models\UplinkMessage;
use App\Services\Uplink\ColonyIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function seedFoods(): void
{
    Food::factory()->create(['food_id' => 'Lettuce', 'calories_per_unit' => 400000]);
    Food::factory()->create(['food_id' => 'FishMeat', 'calories_per_unit' => 1000000]);
}

it('renders the viability page without any uplink data', function (): void {
    $this->get('/colony/viability')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Colony/Viability')
            ->where('status.state', 'never')
            ->where('status.colonyName', null)
            ->where('status.echo', ['channel' => 'uplink', 'event' => '.client-colony.state'])
            ->where('viability', null));
});

it('computes the viability of the most recently seen colony', function (): void {
    seedFoods();
    app(ColonyIngestor::class)->ingest(uplinkFixture());

    $this->get('/colony/viability')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Colony/Viability')
            ->where('status.state', 'ok')
            ->where('status.colonyName', 'DeepWaterGo')
            ->where('status.cycle', 59)
            ->where('status.schema', 1)
            ->where('viability.stockKcal', 7000)
            ->where('viability.scenarios.none.autonomy', 3)
            ->where('viability.menu.0.foodId', 'Lettuce')
            ->where('viability.menu.0.name', 'Lettuce')
            ->where('viability.sources.0.plantName', 'SeaLettuce')
            ->where('viability.reserves.critters.0.name', 'Pacu'));
});

it('links icons only when the asset file exists', function (): void {
    $assets = sys_get_temp_dir().'/colony-assets-'.uniqid();
    File::ensureDirectoryExists("{$assets}/foods");
    File::put("{$assets}/foods/Lettuce.webp", '');
    config(['game.assets_path' => $assets]);
    seedFoods();
    app(ColonyIngestor::class)->ingest(uplinkFixture());

    $this->get('/colony/viability')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viability.menu.0.icon', '/assets/game/foods/Lettuce.webp')
            ->where('viability.menu.1.icon', null)
            ->where('viability.sources.0.plantIcon', null));

    File::deleteDirectory($assets);
});

it('reports a message refused for a newer schema', function (): void {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    app(ColonyIngestor::class)->ingest(uplinkFixture(['schema' => 2]));

    $this->get('/colony/viability')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('status.state', 'newer_schema'));
});

it('reports messages without a stored colony', function (): void {
    app(ColonyIngestor::class)->ingest('{"event":"client-colony.state","channel":"uplink","data":{');

    $this->get('/colony/viability')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('status.state', 'no_colony')
            ->where('viability', null));
});

it('lists uplink messages newest first without their payload', function (): void {
    app(ColonyIngestor::class)->ingest(uplinkFixture());
    $latest = app(ColonyIngestor::class)->ingest(uplinkFixture(['colony' => ['cycle' => 60]]));

    $this->get('/colony/uplink')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Colony/Uplink')
            ->where('status.state', 'ok')
            ->has('messages', 2)
            ->where('messages.0.id', $latest->id)
            ->where('messages.0.cycle', 60)
            ->where('messages.0.outcome', 'stored')
            ->missing('messages.0.payload')
            ->where('selected', null));
});

it('selects a message payload from the query', function (): void {
    $message = app(ColonyIngestor::class)->ingest(uplinkFixture());

    $this->get('/colony/uplink?message='.$message->id)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.id', $message->id)
            ->where('selected.payload', UplinkMessage::query()->sole()->payload));
});

it('selects nothing for an unknown message id', function (): void {
    app(ColonyIngestor::class)->ingest(uplinkFixture());

    $this->get('/colony/uplink?message=999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null));
});
