<?php

declare(strict_types=1);

use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

// Browser tests need the real Vite assets to mount the Vue app.
pest()->beforeEach(function (): void {
    $this->withoutVite();
})->in('Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void
{
    // ..
}

/**
 * The Uplink colony-state envelope of tests/Fixtures/Uplink/state.json, with
 * $override merged into its `data` (objects merged key by key, lists and
 * scalars replaced), re-encoded as the raw message.
 *
 * @param  array<string, mixed>  $override
 */
function uplinkFixture(array $override = []): string
{
    /** @var array{event: string, channel: string, data: array<string, mixed>} $envelope */
    $envelope = json_decode((string) file_get_contents(base_path('tests/Fixtures/Uplink/state.json')), true, flags: JSON_THROW_ON_ERROR);

    $merge = function (array $base, array $override) use (&$merge): array {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])
                ? $merge($base[$key], $value)
                : $value;
        }

        return $base;
    };

    $envelope['data'] = $merge($envelope['data'], $override);

    return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
}

/**
 * The colony state (`data`) of tests/Fixtures/Uplink/state.json.
 *
 * @return array<string, mixed>
 */
function fixtureState(): array
{
    /** @var array{data: array<string, mixed>} $envelope */
    $envelope = json_decode(uplinkFixture(), true, flags: JSON_THROW_ON_ERROR);

    return $envelope['data'];
}

function makeHatch(): Critter
{
    Element::factory()->create(['element_id' => 'Sand', 'name' => ['en' => 'Sand']]);
    Element::factory()->create(['element_id' => 'Carbon', 'name' => ['en' => 'Coal']]);

    $critter = Critter::factory()->create(['critter_id' => 'Hatch', 'variant_family' => 'Hatch', 'name' => ['en' => 'Hatch']]);
    $adult = CritterMorph::factory()->create(['critter_id' => $critter->id, 'morph_id' => 'Hatch', 'is_base' => true]);
    CritterMorphDiet::factory()->create(['critter_morph_id' => $adult->id, 'consumed_element_id' => 'Sand', 'produced_element_id' => 'Carbon', 'amount_per_cycle' => 140, 'conversion_ratio' => 0.5]);
    CritterMorphOutput::factory()->egg()->create(['critter_morph_id' => $adult->id, 'element_id' => 'HatchEgg', 'amount_per_cycle' => 1 / 60]);
    CritterMorph::factory()->baby()->create(['critter_id' => $critter->id, 'morph_id' => 'HatchBaby']);

    return $critter;
}
