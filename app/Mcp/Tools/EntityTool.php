<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Data\ZoneCritterData;
use App\Data\ZoneCrowdingRegionData;
use App\Data\ZoneEggData;
use App\Mcp\Tools\Concerns\ResolvesColony;
use App\Models\Colony;
use App\Models\Zone;
use App\Services\GameText;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\McpHeader;
use App\Services\Zones\ZoneCritterState;
use App\Services\Zones\ZoneGeometry;
use App\Services\Zones\ZonePlants;
use App\Services\Zones\ZonePopulation;
use App\Services\Zones\ZoneProblems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('entity')]
#[Description('Everything the game sent about one plant or building, by its instance id (from zone_map or zone_overview): position, size, state, status items, storage, growth, problems, the zones containing it. Also covers a critter or an egg by its instance id: state, region and crowding, statuses, fertility, next-egg odds.')]
#[IsReadOnly]
final class EntityTool extends Tool
{
    use ResolvesColony;

    public function handle(Request $request, GameText $text): Response
    {
        $request->validate(['instanceId' => ['required']]);
        $instanceId = $request->get('instanceId');
        if (! is_string($instanceId) && ! is_int($instanceId)) {
            return Response::error('instanceId must be the id printed after # (a string or a number).');
        }
        $instanceId = (string) $instanceId;

        $colony = $this->colony($request);
        if (! $colony instanceof Colony) {
            return Response::error($this->colonyMissing($request));
        }

        $zones = Zone::query()->active()->where('colony_guid', $colony->guid)->orderBy('id')
            ->get(['id', 'name', 'layout', 'fast_state', 'slow_state', 'layout_at', 'slow_state_at', 'fast_state_at']);
        $creature = $this->creature($zones, $instanceId);
        if ($creature !== null) {
            return Response::text(implode("\n", [McpHeader::line($colony, $creature['zone']), '', ...$creature['lines']]));
        }

        $source = null;
        $found = null;
        $layoutEntry = null;
        $containing = [];
        $gridWidth = 0;

        foreach ($zones as $zone) {
            $plant = self::byInstance($zone->fast_state['plants'] ?? null, $instanceId);
            $building = self::byInstance($zone->fast_state['buildings'] ?? null, $instanceId);
            $layout = self::byInstance($zone->layout['buildings'] ?? null, $instanceId);

            if ($plant === null && $building === null && $layout === null) {
                continue;
            }

            $containing[] = sprintf('#%d %s', $zone->id, $zone->name);
            $source ??= $zone;
            $found ??= $plant !== null ? ['plant', $plant] : ($building !== null ? ['building', $building] : null);
            $layoutEntry ??= $layout;
            $gridWidth = $gridWidth ?: (int) ($zone->layout['gridWidth'] ?? 0);
        }

        if ($found === null && $layoutEntry === null) {
            return Response::error(sprintf('No plant, building, critter or egg #%s in the zones of colony "%s".', $instanceId, $colony->name));
        }

        [$kind, $entity] = $found ?? ['building', []];
        $prefabId = (string) ($entity['id'] ?? $entity['prefabId'] ?? $layoutEntry['prefabId'] ?? '?');
        $lines = [McpHeader::line($colony, $source), '', sprintf('%s %s (%s) #%s', $kind, $text->name($prefabId), $prefabId, $instanceId)];

        $cell = $kind === 'plant' ? ($entity['cell'] ?? null) : ($layoutEntry['anchorCell'] ?? null);
        if (is_int($cell) && $gridWidth > 0) {
            [$x, $y] = ZoneGeometry::xy($cell, $gridWidth);
            $lines[] = 'at '.McpFormat::world($x, $y).($layoutEntry === null ? '' : sprintf(' %dx%d %s (anchor = bottom-left)', $layoutEntry['width'] ?? 1, $layoutEntry['height'] ?? 1, $layoutEntry['orientation'] ?? 'Neutral'));
        }

        if ($kind === 'plant') {
            $growth = ZonePlants::growth($entity);
            $lines[] = sprintf('growth %s%s', $growth === null ? 'unavailable' : round($growth * 100).' %', ($entity['grown'] ?? false) !== true && is_numeric($entity['secondsUntilHarvest'] ?? null) ? ' · next harvest in '.round((float) $entity['secondsUntilHarvest']).' s' : '');
        }

        foreach ($kind === 'plant' ? $entity : array_diff_key($entity, ['statusItems' => true, 'storage' => true]) as $key => $value) {
            if (! in_array($key, ['id', 'prefabId', 'instanceId'], true)) {
                $lines[] = sprintf('%s: %s', $key, self::scalar($value));
            }
        }

        if ($kind === 'building' && $entity !== []) {
            foreach ((array) ($entity['statusItems'] ?? []) as $status) {
                if (is_array($status)) {
                    $lines[] = sprintf('status %s: %s', $status['id'] ?? '?', $status['name'] ?? '');
                }
            }
            foreach ((array) ($entity['storage'] ?? []) as $item) {
                if (is_array($item)) {
                    $lines[] = sprintf('storage %s %s', $item['id'] ?? '?', McpFormat::kg(is_numeric($item['mass'] ?? null) ? (float) $item['mass'] : null));
                }
            }
        }
        if ($found === null) {
            $lines[] = 'state unavailable (in the layout, not in the fast state)';
        }

        $problems = $kind === 'plant' ? ZoneProblems::forPlant($entity) : ZoneProblems::forBuilding($entity);
        $lines[] = 'problems: '.match (true) {
            $found === null => 'unavailable',
            $problems === [] => 'none',
            default => implode('; ', $problems),
        };
        $lines[] = 'zones: '.implode(', ', $containing);
        $lines[] = 'game data: lookup '.$prefabId;

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'instanceId' => $schema->string()->description('Instance id of a plant, building, critter or egg, as zone_map and zone_overview print it after #.')->required(),
            'colony' => $this->colonySchema($schema),
        ];
    }

    /**
     * @return list<string>
     */
    private static function critterLines(ZoneCritterData $critter, ?ZoneCrowdingRegionData $region, int $gridWidth, Zone $zone): array
    {
        $percent = fn (?float $ratio): string => $ratio === null ? 'unavailable' : round($ratio * 100).' %';
        $lines = [
            sprintf('critter %s (%s) #%s', $critter->name, $critter->prefabId, $critter->instanceId),
            self::position($critter->cell, $gridWidth).sprintf(' · %s · %s%s', $critter->tame ? 'tame' : 'wild', $critter->aquatic ? 'aquatic' : $critter->navType, $critter->baby ? ' · baby' : ''),
            sprintf('age %s of %s cycles', $critter->ageCycles === null ? 'unavailable' : McpFormat::number($critter->ageCycles), $critter->lifespanCycles === null ? 'unavailable' : McpFormat::number($critter->lifespanCycles)),
            sprintf('calories %s%s', $percent($critter->caloriesRatio), $critter->hungry ? ' (hungry)' : ''),
            'fertility '.$percent($critter->fertilityRatio),
            'happiness '.($critter->happiness === null ? 'unavailable' : sprintf('%d (%s)', $critter->happiness, $critter->happinessState ?? '?')),
            'region '.($critter->regionId === null ? 'none (carried)' : $critter->regionId).self::crowdingText($region, $critter->prefabId),
            'statuses: '.($critter->statuses === [] ? 'none' : implode(', ', array_map(fn (array $status): string => $status['name'].($status['bad'] ? ' (bad)' : ''), $critter->statuses))),
            'next egg: '.($critter->breeding === [] ? 'none' : implode(', ', array_map(fn (array $odds): string => sprintf('%s %d %%', $odds['egg'], round($odds['weight'] * 100)), $critter->breeding))),
            'stomach: '.($critter->stomach === [] ? 'empty' : implode(', ', array_map(fn (array $meal): string => sprintf('%s %d kcal', $meal['food'], round($meal['calories'])), $critter->stomach))),
            'next poop: '.($critter->nextPoop ?? 'unavailable'),
            'zones: '.sprintf('#%d %s', $zone->id, $zone->name),
            'game data: lookup '.$critter->prefabId,
        ];

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function eggLines(ZoneEggData $egg, ?ZoneCrowdingRegionData $region, int $gridWidth, Zone $zone): array
    {
        $percent = fn (?float $value): string => $value === null ? 'unavailable' : round($value).' %';

        return [
            sprintf('egg %s (%s) #%s', $egg->name, $egg->prefabId, $egg->instanceId),
            self::position($egg->cell, $gridWidth).' · '.($egg->tame ? 'tame' : 'wild').' · '.($egg->location ?? 'location unavailable'),
            'incubation '.$percent($egg->incubation),
            'viability '.$percent($egg->viability),
            'hatches into '.($egg->spawns ?? 'unavailable'),
            'holder '.($egg->holderId === null ? 'none' : '#'.$egg->holderId),
            'region '.($egg->regionId ?? 'none').($region === null ? '' : sprintf(' (%d / %d cells occupied)', $region->occupied, $region->cellCount)),
            'zones: '.sprintf('#%d %s', $zone->id, $zone->name),
            'game data: lookup '.$egg->prefabId,
        ];
    }

    private static function position(int $cell, int $gridWidth): string
    {
        if ($gridWidth <= 0) {
            return 'at cell '.$cell;
        }
        [$x, $y] = ZoneGeometry::xy($cell, $gridWidth);

        return 'at '.McpFormat::world($x, $y);
    }

    /** The region's counts and the species' crowding flags, as " (...)"; empty when the region is unknown. */
    private static function crowdingText(?ZoneCrowdingRegionData $region, string $prefabId): string
    {
        if ($region === null) {
            return '';
        }

        $state = $region->states[$prefabId] ?? null;
        $flags = $state === null ? [] : ZonePopulation::shownStates($state['overcrowded'], $state['cramped'], $state['confined']);

        return sprintf(' (%s %d / %d cells occupied, %d %s%s)', $region->kind, $region->occupied, $region->cellCount, $region->counts[$prefabId] ?? 0, $prefabId, $flags === [] ? '' : ', '.implode(' ', $flags));
    }

    /** A field value as text: JSON for arrays, true / false / null spelled out. */
    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function byInstance(mixed $entities, string $instanceId): ?array
    {
        foreach (is_array($entities) ? $entities : [] as $entity) {
            if (is_array($entity) && (string) ($entity['instanceId'] ?? '') === $instanceId) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * The critter or egg with this instance id in the zones, described.
     *
     * @param  Collection<int, Zone>  $zones
     * @return array{zone: Zone, lines: list<string>}|null
     */
    private function creature(Collection $zones, string $instanceId): ?array
    {
        foreach ($zones as $zone) {
            $regions = collect(ZoneCritterState::regions($zone) ?? [])->keyBy('id');
            $critter = collect(ZoneCritterState::critters($zone) ?? [])->firstWhere('instanceId', $instanceId);
            $egg = collect(ZoneCritterState::eggs($zone) ?? [])->firstWhere('instanceId', $instanceId);
            $gridWidth = (int) ($zone->layout['gridWidth'] ?? 0);

            if ($critter instanceof ZoneCritterData) {
                return ['zone' => $zone, 'lines' => self::critterLines($critter, $regions->get($critter->regionId), $gridWidth, $zone)];
            }
            if ($egg instanceof ZoneEggData) {
                return ['zone' => $zone, 'lines' => self::eggLines($egg, $regions->get($egg->regionId), $gridWidth, $zone)];
            }
        }

        return null;
    }
}
