<?php

declare(strict_types=1);

use App\Services\GameImport\GameDataTables;

it('discovers game-data tables including codex and model-less pivots', function (): void {
    $tables = GameDataTables::all();

    expect($tables)->toContain('buildings')
        ->toContain('elements')
        ->toContain('codex_entries')
        ->toContain('codexables')
        ->toContain('plant_variant_mutations');
});

it('excludes non-game tables such as users', function (): void {
    expect(GameDataTables::all())->not->toContain('users');
});

it('returns exactly the expected number of game tables (30 models + 2 pivots)', function (): void {
    // 27 entity models + CodexEntry + PrefabName = 30 #[GameData] models (incl. Mod), plus 2 model-less pivots.
    // A new game model added without #[GameData] would drop this count and fail here.
    expect(GameDataTables::all())->toHaveCount(32);
});
