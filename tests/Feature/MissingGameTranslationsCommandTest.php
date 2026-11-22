<?php

declare(strict_types=1);

use App\Models\CodexEntry;
use App\Models\CritterMorphDiet;
use App\Models\Mod;
use App\Models\PrefabName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('reports only imported missing translations and separates unmapped references', function (): void {
    $root = sys_get_temp_dir().'/oni-audit-'.uniqid();
    File::ensureDirectoryExists($root.'/StreamingAssets/strings');
    File::ensureDirectoryExists($root.'/mods/123/translations');

    try {
        config(['game.mods_path' => $root.'/mods']);
        $mod = Mod::factory()->create(['workshop_id' => '123']);
        $food = 'STRINGS.ITEMS.FOOD.FISHFOOD.NAME';
        $tofu = 'STRINGS.ITEMS.FOOD.TOFU.NAME';
        $description = 'STRINGS.ITEMS.FOOD.TOFU.DESC';
        $body = 'STRINGS.CODEX.PACU.BODY';
        $entry = fn (string $key, string $source, string $translation = ''): string => "msgctxt \"$key\"\nmsgid ".json_encode($source, JSON_UNESCAPED_SLASHES)."\nmsgstr ".json_encode($translation, JSON_UNESCAPED_SLASHES)."\n\n";
        File::put($root.'/StreamingAssets/strings/strings_template.pot', $entry($food, '<link="PACUTREAT">Pacu Treat</link>').$entry($body, 'Pacus enjoy seeds.').$entry('STRINGS.UNUSED.NAME', 'Not imported'));
        File::put($root.'/fr.po', '');
        File::put($root.'/mods/123/translation_template.pot', $entry($tofu, 'Tofu').$entry($description, 'Made from beans.'));
        File::put($root.'/mods/123/translations/fr.po', $entry($tofu, 'Tofu', 'Tofu'));

        PrefabName::factory()->create(['prefab_id' => 'FishFood', 'name_key' => $food, 'mod_id' => null]);
        PrefabName::factory()->create(['prefab_id' => 'Tofu', 'name_key' => $tofu, 'mod_id' => $mod->id]);
        CritterMorphDiet::factory()->create(['consumed_element_id' => 'UnknownSeed', 'produced_element_id' => null]);
        CodexEntry::factory()->create(['codex_id' => 'Pacu', 'title_key' => null, 'content' => [
            ['blocks' => [['type' => 'text', 'key' => $body]]],
        ]]);

        expect(Artisan::call('game:translations:missing', ['--json' => true, '--game-path' => $root, '--translations-path' => $root]))->toBe(0);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['locale'])->toBe('fr')
            ->and($report['missing'])->toHaveCount(3)
            ->and(collect($report['missing'])->keyBy('key')->map(fn (array $row): array => array_filter(array_intersect_key($row, array_flip(['id', 'source', 'workshop_id'])), fn ($value): bool => $value !== null))->all())->toMatchArray([
                $food => ['id' => 'FishFood', 'source' => '<link="PACUTREAT">Pacu Treat</link>'],
                $body => ['id' => 'Pacu', 'source' => 'Pacus enjoy seeds.'],
                $description => ['id' => 'Tofu', 'source' => 'Made from beans.', 'workshop_id' => '123'],
            ])
            ->and($report['unmapped'])->toContain('UnknownSeed')
            ->not->toContain('FishFood', 'Tofu');
    } finally {
        File::deleteDirectory($root);
    }
});
