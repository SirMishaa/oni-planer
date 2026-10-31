<?php

declare(strict_types=1);

use App\Services\GameImport\StringResolver;

it('resolves a localization id to english string', function (): void {
    $potPath = base_path('tests/Fixtures/GameImport/strings.pot');
    $resolver = new StringResolver($potPath);

    expect($resolver->resolve('STRINGS.ELEMENTS.CARBONDIOXIDE.NAME'))->toBe('Carbon Dioxide')
        ->and($resolver->resolve('STRINGS.ELEMENTS.OXYGEN.NAME'))->toBe('Oxygen');
});

it('returns null for unknown localization id', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolve('STRINGS.UNKNOWN.KEY'))->toBeNull();
});

it('builds a name json with english key', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolveToJson('STRINGS.ELEMENTS.OXYGEN.NAME'))->toBe(['en' => 'Oxygen']);
});

it('resolves a multi-line continuation block into the full concatenated value', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolve('STRINGS.CODEX.TEST.MULTILINE'))
        ->toBe('First part of the body. Second part with a link.');
});

it('resolves keys written in nested-type notation', function (): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->resolve('STRINGS.CREATURES/SPECIES/TESTMEALWOOD::NAME'))->toBe('Test Mealwood');
});

it('finds the name key of a prefab id from its STRINGS entry', function (string $prefabId, ?string $expectedKey): void {
    $resolver = new StringResolver(base_path('tests/Fixtures/GameImport/strings.pot'));

    expect($resolver->findNameKey($prefabId))->toBe($expectedKey);
})->with([
    'food item' => ['DinosaurMeat', 'STRINGS.ITEMS.FOOD.DINOSAURMEAT.NAME'],
    'underscored species, not its seed' => ['SpiceVine', 'STRINGS.CREATURES.SPECIES.SPICE_VINE.NAME'],
    'unknown prefab' => ['NoSuchThing', null],
]);
