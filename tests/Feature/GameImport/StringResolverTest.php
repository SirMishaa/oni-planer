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
