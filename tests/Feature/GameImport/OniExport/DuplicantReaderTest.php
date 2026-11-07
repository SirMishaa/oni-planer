<?php

declare(strict_types=1);

use App\Models\DuplicantBaseStat;
use App\Models\DuplicantTrait;
use App\Services\GameImport\DuplicantImporter;
use App\Services\GameImport\OniExport\DuplicantReader;
use App\Services\GameImport\OniExport\OniExport;
use App\Services\GameImport\StringResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fixtureDuplicantReader(): DuplicantReader
{
    return new DuplicantReader(new OniExport(base_path('tests/Fixtures/OniExport')));
}

/** @return array<string, array<string, mixed>> */
function readFixtureTraits(): array
{
    return collect(fixtureDuplicantReader()->records()['traits'])->keyBy('trait_id')->all();
}

it('derives the base stats from the standard duplicant', function (): void {
    expect(fixtureDuplicantReader()->records()['base_stats'])->toBe([
        'oxygen_consumption_gs' => 100.0,
        'co2_production_gs' => 2.0,
        'calories_per_cycle' => 1000,
        'mass_kg' => 30.0,
        'bladder_fill_per_cycle' => 1.0,
    ]);
});

it('keeps duplicant traits only, once each', function (): void {
    $traits = fixtureDuplicantReader()->records()['traits'];

    expect(array_column($traits, 'trait_id'))->toBe(['Ellie', 'Anemic', 'Greasemonkey']);
});

it('reads trait effects with the raw sign and scale', function (): void {
    $traits = readFixtureTraits();

    expect($traits['Ellie']['is_positive'])->toBeFalse()
        ->and($traits['Ellie']['effects'])->toBe([
            ['stat' => 'air_consumption_rate', 'modifier' => -0.045, 'modifier_type' => 'add'],
            ['stat' => 'decor_expectation', 'modifier' => -5.0, 'modifier_type' => 'add'],
        ])
        ->and($traits['Greasemonkey']['is_positive'])->toBeTrue()
        ->and($traits['Greasemonkey']['effects'][1])->toBe(['stat' => 'digging', 'modifier' => 1.5, 'modifier_type' => 'multiply']);
});

it('resolves trait name keys directly or by reverse lookup of the localized name', function (): void {
    $traits = readFixtureTraits();

    expect($traits['Anemic']['name_localization_id'])->toBe('STRINGS.DUPLICANTS.TRAITS.ANEMIC.NAME')
        ->and($traits['Ellie']['name_localization_id'])->toBe('STRINGS.DUPLICANTS.CONGENITALTRAITS.ELLIE.NAME')
        ->and($traits['Ellie']['dlc_id'])->toBeNull();
});

it('imports duplicant records', function (): void {
    (new DuplicantImporter(new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'))))
        ->importRecords(fixtureDuplicantReader()->records());

    $ellie = DuplicantTrait::query()->where('trait_id', 'Ellie')->firstOrFail();

    expect(DuplicantBaseStat::query()->count())->toBe(1)
        ->and(DuplicantTrait::query()->count())->toBe(3)
        ->and($ellie->effects()->where('stat', 'air_consumption_rate')->value('modifier'))->toBe(-0.045);
});
