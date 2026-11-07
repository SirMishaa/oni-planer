<?php

declare(strict_types=1);

use App\Models\Element;
use App\Models\Mod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Element::query()->create([
        'element_id' => 'Water', 'state' => 'liquid', 'molar_mass' => 18.0,
        'toxicity' => 0, 'material_category' => 'Water', 'tags' => [],
        'name' => ['en' => 'Water'], 'is_disabled' => false,
    ]);
    Element::query()->create([
        'element_id' => 'Iron', 'state' => 'solid', 'molar_mass' => 55.8,
        'toxicity' => 0, 'material_category' => 'Metal', 'tags' => [],
        'name' => ['en' => 'Iron'], 'is_disabled' => false,
    ]);
    Element::query()->create([
        'element_id' => 'VacuumDisabled', 'state' => 'special', 'molar_mass' => 0,
        'toxicity' => 0, 'material_category' => 'Special', 'tags' => [],
        'name' => ['en' => 'Vacuum'], 'is_disabled' => true,
    ]);
});

it('renders the elements page via Inertia', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Elements/Index')
        );
});

it('passes all non-disabled elements as props', function (): void {
    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('elements', 2)
        );
});

it('returns elements with expected fields', function (): void {
    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('elements.0', fn (AssertableInertia $el): AssertableInertia => $el
                ->hasAll(['element_id', 'name', 'state', 'material_category', 'mod'])
            )
        );
});

it('exposes the mod on modded elements in the list and on the detail page', function (): void {
    $mod = Mod::factory()->create(['title' => 'Some Element Mod']);
    Element::query()->where('element_id', 'Iron')->update(['mod_id' => $mod->id]);

    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('elements.1.element_id', 'Iron')
            ->where('elements.1.mod.title', 'Some Element Mod')
            ->where('elements.0.mod', null)
        );

    $this->get(route('elements.show', 'Iron'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('element.mod.title', 'Some Element Mod')
        );
    $this->get(route('elements.show', 'Water'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('element.mod', null)
        );
});
