<?php

declare(strict_types=1);

use App\Models\Zone;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;

it('has the same zones keys in french and english', function (): void {
    $french = Arr::dot(trans('zones', [], 'fr'));
    $english = Arr::dot(trans('zones', [], 'en'));

    expect(array_keys($french))->toEqualCanonicalizing(array_keys($english))
        ->and(array_filter($french, fn (mixed $value): bool => $value === '' || $value === []))->toBeEmpty()
        ->and(array_filter($english, fn (mixed $value): bool => $value === '' || $value === []))->toBeEmpty();
});

it('shares the zones translations with the pages', function (): void {
    app()->setLocale('fr');

    $this->get(route('zones.show', Zone::factory()->create()))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('translations.zones.nav.zones', trans('zones.nav.zones', [], 'fr'))
            ->where('translations.zones.problems.wilting', 'Flétrit'));
});
