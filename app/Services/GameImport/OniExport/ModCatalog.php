<?php

declare(strict_types=1);

namespace App\Services\GameImport\OniExport;

use App\Models\Mod;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves which mod owns a prefab and whether the mod is supported.
 *
 * Ownership comes from the export: building.json `mods[].buildings` for
 * buildings, planner_extras.json `entities` / `elements` `sourceMod` for the
 * rest. A prefab with no owner belongs to the base game. Recipes are resolved
 * by the recipe reader through {@see self::modIdForWorkshop()}.
 */
final class ModCatalog
{
    /** @var array<string, int|null> */
    private array $modIds = [];

    /**
     * @param  array<string, string>  $ownerByPrefab  prefab id => owner key (workshop id, or `local:<staticID>`)
     * @param  array<string, array{static_id: string|null, title: string}>  $exportMods  owner key => export metadata
     * @param  list<string>  $supported
     */
    private function __construct(
        private readonly array $ownerByPrefab,
        private readonly array $exportMods,
        private readonly string $modsPath,
        private readonly array $supported,
    ) {}

    /** @param  list<string>  $supported */
    public static function fromExport(OniExport $export, string $modsPath, array $supported): self
    {
        $ownerByPrefab = [];
        $exportMods = [];

        foreach ($export->json('building.json')['mods'] ?? [] as $mod) {
            $owner = (string) $mod['id'];
            $exportMods[$owner] ??= ['static_id' => null, 'title' => (string) $mod['title']];

            foreach ($mod['buildings'] as $prefabId) {
                $ownerByPrefab[$prefabId] = $owner;
            }
        }

        $extras = self::plannerExtras($export);

        foreach (['entities', 'elements'] as $section) {
            foreach ($extras[$section] ?? [] as $prefabId => $data) {
                $sourceMod = $data['sourceMod'] ?? null;

                if ($sourceMod === null) {
                    continue;
                }

                $owner = $sourceMod['workshopId'] !== null
                    ? (string) $sourceMod['workshopId']
                    : 'local:'.($sourceMod['assembly'] ?? $sourceMod['staticID'] ?? $prefabId);
                $exportMods[$owner] ??= ['static_id' => $sourceMod['staticID'] ?? null, 'title' => (string) ($sourceMod['title'] ?? $owner)];
                $ownerByPrefab[(string) $prefabId] = $owner;
            }
        }

        return new self($ownerByPrefab, $exportMods, $modsPath, $supported);
    }

    /** Id of the persisted Mod owning the prefab, null for base game or unsupported mods. */
    public function modIdFor(string $prefabId): ?int
    {
        $owner = $this->ownerByPrefab[$prefabId] ?? null;

        if ($owner === null || ! $this->isSupportedOwner($owner)) {
            return null;
        }

        return $this->modIdForWorkshop($owner);
    }

    /** Whether the prefab belongs to a mod that is not in the supported list. */
    public function isSkipped(string $prefabId): bool
    {
        $owner = $this->ownerByPrefab[$prefabId] ?? null;

        return $owner !== null && ! $this->isSupportedOwner($owner);
    }

    public function modIdForWorkshop(string $workshopId): ?int
    {
        if (! array_key_exists($workshopId, $this->modIds)) {
            $this->modIds[$workshopId] = Mod::query()->where('workshop_id', $workshopId)->value('id');
        }

        return $this->modIds[$workshopId];
    }

    /** Upserts a Mod row per supported mod present in the export or in the synced mods directory. */
    public function persist(): void
    {
        foreach ($this->supported as $workshopId) {
            $metadata = $this->modYaml($workshopId);
            $exported = $this->exportMods[$workshopId] ?? null;

            if ($metadata === null && $exported === null) {
                continue;
            }

            $mod = Mod::query()->updateOrCreate(['workshop_id' => $workshopId], [
                'static_id' => $metadata['staticID'] ?? $exported['static_id'] ?? null,
                'title' => $metadata['title'] ?? $exported['title'],
            ]);

            $this->modIds[$workshopId] = $mod->id;
        }
    }

    /** @return list<array{workshop_id: string, title: string, entities: int}> */
    public function skippedReport(): array
    {
        $counts = [];

        foreach ($this->ownerByPrefab as $owner) {
            if (! $this->isSupportedOwner($owner)) {
                $counts[$owner] = ($counts[$owner] ?? 0) + 1;
            }
        }

        $report = [];

        foreach ($counts as $owner => $count) {
            $report[] = [
                'workshop_id' => (string) $owner,
                'title' => $this->exportMods[$owner]['title'],
                'entities' => $count,
            ];
        }

        return $report;
    }

    /** @return list<string> */
    public function potPaths(): array
    {
        return $this->existing(fn (string $id): array => [$this->modsPath.'/'.$id.'/translation_template.pot']);
    }

    /** @return list<string> */
    public function poPaths(string $locale): array
    {
        return $this->existing(fn (string $id): array => [$this->modsPath.'/'.$id.'/translations/'.$locale.'.po']);
    }

    /** @return list<string> */
    public function elementYamlPaths(): array
    {
        return $this->existing(fn (string $id): array => File::glob($this->modsPath.'/'.$id.'/elements/*.yaml'));
    }

    /** @return array<string, mixed> */
    private static function plannerExtras(OniExport $export): array
    {
        try {
            return $export->json('planner_extras.json');
        } catch (OniExportMissing) {
            return [];
        }
    }

    private function isSupportedOwner(string $owner): bool
    {
        return in_array($owner, $this->supported, true);
    }

    /** @return array<string, mixed>|null */
    private function modYaml(string $workshopId): ?array
    {
        $path = $this->modsPath.'/'.$workshopId.'/mod.yaml';

        return File::isFile($path) ? Yaml::parseFile($path) : null;
    }

    /**
     * @param  callable(string): list<string>  $pathsFor
     * @return list<string>
     */
    private function existing(callable $pathsFor): array
    {
        $paths = [];

        foreach ($this->supported as $workshopId) {
            foreach ($pathsFor($workshopId) as $path) {
                if (File::isFile($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }
}
