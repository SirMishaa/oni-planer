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
        $this->withoutVite();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void
{
    // ..
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
