<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\CodexEntry;
use App\Models\Critter;
use App\Models\CritterMorph;
use App\Models\CritterMorphDiet;
use App\Models\CritterMorphOutput;
use App\Models\Element;
use App\Models\Food;
use App\Models\GeyserType;
use App\Models\Mod;
use App\Models\Plant;
use App\Models\PrefabName;
use App\Services\GameImport\ModStringKey;
use App\Services\GameImport\PoFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('game:translations:missing
    {--locale=fr : Locale to audit}
    {--json : Emit a machine-readable report}
    {--game-path=game-data/OxygenNotIncluded_Data : Game data containing the strings template}
    {--translations-path=game-data/translations : Directory containing locale PO files}')]
#[Description('Report missing source translations for the imported game catalogue without modifying it')]
final class MissingGameTranslationsCommand extends Command
{
    public function handle(): int
    {
        $locale = (string) $this->option('locale');
        if (! preg_match('/\A[a-z]{2,3}(?:[-_][a-zA-Z0-9]+)*\z/', $locale)) {
            $this->error('Invalid locale code.');

            return self::FAILURE;
        }

        $pot = $this->option('game-path').'/StreamingAssets/strings/strings_template.pot';
        $po = $this->option('translations-path').'/'.$locale.'.po';
        foreach ([$pot, $po] as $path) {
            if (! File::isReadable($path)) {
                $this->error('Translation source not readable: '.$path);

                return self::FAILURE;
            }
        }

        $sources = PoFile::read($pot)->sources(stripMarkup: false);
        $translations = PoFile::read($po)->translations();
        $mods = Mod::query()->orderBy('id')->pluck('workshop_id', 'id');
        foreach ($mods as $workshopId) {
            $directory = config()->string('game.mods_path').'/'.$workshopId;
            $template = $directory.'/translation_template.pot';
            $translation = $directory.'/translations/'.$locale.'.po';
            if (File::isFile($template)) {
                $sources = [...$sources, ...ModStringKey::normalizeAll(PoFile::read($template)->sources(stripMarkup: false))];
            }
            if (File::isFile($translation)) {
                $translations = [...$translations, ...ModStringKey::normalizeAll(PoFile::read($translation)->translations())];
            }
        }

        $missing = [];
        $mapped = [];
        $withoutSource = [];
        $record = static function (string $kind, string $id, string $key, ?string $workshopId, bool $expected = true) use ($sources, $translations, &$missing, &$withoutSource): void {
            if (! isset($sources[$key]) && $expected) {
                $withoutSource[] = ['kind' => $kind, 'id' => $id, 'key' => $key, 'workshop_id' => $workshopId];
            }
            if (isset($sources[$key]) && mb_trim($translations[$key] ?? '') === '') {
                $missing[] = ['kind' => $kind, 'id' => $id, 'key' => $key, 'source' => $sources[$key], 'workshop_id' => $workshopId];
            }
        };

        foreach (PrefabName::query()->orderBy('prefab_id')->get(['prefab_id', 'name_key', 'mod_id']) as $prefab) {
            $mapped[] = $prefab->prefab_id;
            $workshopId = $mods->get($prefab->mod_id);
            $record('prefab', $prefab->prefab_id, $prefab->name_key, $workshopId);
            if (str_ends_with($prefab->name_key, '.NAME')) {
                foreach (['DESC', 'EFFECT'] as $suffix) {
                    $key = mb_substr($prefab->name_key, 0, -4).$suffix;
                    $record('prefab', $prefab->prefab_id, $key, $workshopId, expected: false);
                }
            }
        }

        foreach (CodexEntry::query()->orderBy('codex_id')->cursor() as $article) {
            $keys = $article->title_key === null ? [] : [$article->title_key];
            foreach ($article->content ?? [] as $container) {
                foreach ($container['blocks'] ?? [] as $block) {
                    if (is_string($block['key'] ?? null)) {
                        $keys[] = $block['key'];
                    }
                }
            }
            foreach (array_unique($keys) as $key) {
                $record('codex', $article->codex_id, $key, null);
            }
        }

        $unmapped = $this->importedIds()->diff($mapped)->unique()->sort()->values()->all();
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'locale' => $locale,
                'missing' => $missing,
                'unmapped' => $unmapped,
                'missing_source' => $withoutSource,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
        } else {
            $this->info(sprintf('%s: %d missing entries (%d distinct keys), %d unmapped prefabs, %d entries without source text.', $locale, count($missing), count(array_unique(array_column($missing, 'key'))), count($unmapped), count($withoutSource)));
            if ($missing !== []) {
                $this->table(['Kind', 'ID', 'STRINGS key', 'English source', 'Workshop ID'], $missing);
            }
            if ($unmapped !== []) {
                $this->line('No STRINGS mapping: '.implode(', ', $unmapped));
            }
            if ($withoutSource !== []) {
                $this->table(['Kind', 'ID', 'Key absent from source templates', 'Workshop ID'], $withoutSource);
            }
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function importedIds(): \Illuminate\Support\Collection
    {
        return collect([
            ...Element::query()->pluck('element_id'),
            ...Building::query()->pluck('building_id'),
            ...Critter::query()->pluck('critter_id'),
            ...CritterMorph::query()->pluck('morph_id'),
            ...Plant::query()->pluck('plant_id'),
            ...Food::query()->pluck('food_id'),
            ...GeyserType::query()->pluck('geyser_id'),
            ...CritterMorphDiet::query()->pluck('consumed_element_id'),
            ...CritterMorphDiet::query()->whereNotNull('produced_element_id')->pluck('produced_element_id'),
            ...CritterMorphOutput::query()->pluck('element_id'),
        ]);
    }
}
