<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Models\Colony;
use App\Models\Element;
use App\Models\Zone;
use App\Services\GameText;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZoneTerrainSummary;
use App\Services\Zones\ZoneCritters;
use App\Services\Zones\ZoneGeometry;
use App\Services\Zones\ZonePlants;
use App\Services\Zones\ZoneProblems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_overview')]
#[Description('One zone in one screen: buildings and their states, plants and growth, critters inside (derived from their cell), elements with mass and temperature ranges, problems, and pointers to zone_map, captures and the app page.')]
#[IsReadOnly]
final class ZoneOverviewTool extends Tool
{
    use ResolvesZone;

    public function handle(Request $request, GameText $text): Response
    {
        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $lines = [
            McpHeader::line($colony, $zone),
            '',
            sprintf('#%d %s · world %d · version %d · %d cells%s', $zone->id, $zone->name, $zone->world_id, $zone->version, $zone->cell_count, $zone->orphaned ? ' · orphaned' : ''),
            $this->boxLine($zone),
            '',
            ...$this->buildings($zone, $text),
            '',
            ...$this->plants($zone, $text),
            '',
            ...$this->critters($zone, $colony, $text),
            '',
            ...$this->elements($zone, $text),
            '',
            ...$this->problems($zone),
            '',
            sprintf('More: zone_map for positions · %d captures (zone_captures) · app page %s', $zone->captures()->count(), route('zones.show', $zone)),
        ];

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['zone' => $this->zoneSchema($schema), 'colony' => $this->colonySchema($schema)];
    }

    /** "12.5/100", or "unavailable". */
    private static function valueOfMax(mixed $amount): string
    {
        if (! is_array($amount) || ! is_numeric($amount['value'] ?? null)) {
            return 'unavailable';
        }

        return McpFormat::number((float) $amount['value']).'/'.(is_numeric($amount['max'] ?? null) ? McpFormat::number((float) $amount['max']) : '?');
    }

    private function boxLine(Zone $zone): string
    {
        $box = $zone->layout['box'] ?? null;

        return is_array($box)
            ? sprintf('box %dx%d @ %s (bottom-left, includes the border ring)', $box['width'] ?? 0, $box['height'] ?? 0, McpFormat::world((int) ($box['x'] ?? 0), (int) ($box['y'] ?? 0)))
            : 'layout unavailable';
    }

    /** @return list<string> */
    private function buildings(Zone $zone, GameText $text): array
    {
        $buildings = $zone->fast_state['buildings'] ?? null;
        if (! is_array($buildings)) {
            return [$zone->fast_state === null ? 'fast state unavailable' : 'Buildings: unavailable'];
        }

        [$content, $passages] = ZoneProblems::splitPassages(array_values(array_filter($buildings, is_array(...))));
        $groups = [];
        foreach ($content ?? [] as $building) {
            $groups[(string) ($building['prefabId'] ?? '?')][] = $building;
        }
        ksort($groups);

        $lines = ['Buildings:'];
        foreach ($groups as $prefabId => $group) {
            $statuses = [];
            $storage = [];
            foreach ($group as $building) {
                foreach ((array) ($building['statusItems'] ?? []) as $status) {
                    if (is_array($status)) {
                        $statuses[] = (string) ($status['name'] ?? $status['id'] ?? '?');
                    }
                }
                foreach ((array) ($building['storage'] ?? []) as $item) {
                    if (is_array($item) && is_string($item['id'] ?? null)) {
                        $storage[$item['id']] = ($storage[$item['id']] ?? 0.0) + (is_numeric($item['mass'] ?? null) ? (float) $item['mass'] : 0.0);
                    }
                }
            }
            $lines[] = sprintf(
                ' %s (%s) ×%d · operational %d/%d · active %d/%d%s%s',
                $text->name($prefabId),
                $prefabId,
                count($group),
                count(array_filter($group, fn (array $building): bool => ($building['operational'] ?? null) === true)),
                count($group),
                count(array_filter($group, fn (array $building): bool => ($building['active'] ?? null) === true)),
                count($group),
                $statuses === [] ? '' : ' · status: '.implode('; ', array_unique($statuses)),
                $storage === [] ? '' : ' · storage: '.implode(', ', array_map(fn (string $id, float $mass): string => $id.' '.McpFormat::kg($mass), array_keys($storage), $storage)),
            );
        }
        if ($groups === []) {
            $lines[] = ' none';
        }
        if ($passages !== []) {
            $lines[] = ' border passages: '.implode(', ', array_map(fn (array $building): string => (string) ($building['prefabId'] ?? '?'), $passages));
        }

        return $lines;
    }

    /** @return list<string> */
    private function plants(Zone $zone, GameText $text): array
    {
        $plants = $zone->fast_state['plants'] ?? null;
        if (! is_array($plants)) {
            return ['Plants: unavailable'];
        }

        $groups = [];
        foreach (array_filter($plants, is_array(...)) as $plant) {
            $groups[(string) ($plant['id'] ?? '?')][] = $plant;
        }
        ksort($groups);

        $lines = ['Plants:'];
        foreach ($groups as $plantId => $group) {
            $growths = array_filter(array_map(ZonePlants::growth(...), $group), fn (?float $growth): bool => $growth !== null);
            $waits = array_filter(array_map(fn (array $plant): ?float => ($plant['grown'] ?? false) !== true && is_numeric($plant['secondsUntilHarvest'] ?? null) ? (float) $plant['secondsUntilHarvest'] : null, $group), fn (?float $seconds): bool => $seconds !== null);
            $lines[] = sprintf(
                ' %s (%s) ×%d · growth avg %s · ready %d · wilting %d%s',
                $text->name($plantId),
                $plantId,
                count($group),
                $growths === [] ? 'unavailable' : round(array_sum($growths) / count($growths) * 100).' %',
                count(array_filter($group, fn (array $plant): bool => ($plant['grown'] ?? false) === true)),
                count(array_filter($group, fn (array $plant): bool => ($plant['wilting'] ?? false) === true)),
                $waits === [] ? '' : ' · next harvest in '.round(min($waits)).' s',
            );
        }
        if ($groups === []) {
            $lines[] = ' none';
        }

        return $lines;
    }

    /** @return list<string> */
    private function critters(Zone $zone, Colony $colony, GameText $text): array
    {
        $gridWidth = (int) ($zone->layout['gridWidth'] ?? 0);
        if (! is_array($colony->state['critters'] ?? null) || $gridWidth <= 0) {
            return ['Critters: unavailable'];
        }

        $critters = ZoneCritters::in($zone, $colony);
        $lines = ['Critters (derived from their cell):'];
        foreach ($critters as $critter) {
            [$x, $y] = ZoneGeometry::xy((int) $critter['cell'], $gridWidth);
            $lines[] = sprintf(
                ' %s (%s) @ %s %s age %s cal %s (derived)',
                $text->name((string) ($critter['id'] ?? '?')),
                $critter['id'] ?? '?',
                McpFormat::world($x, $y),
                match ($critter['tame'] ?? null) {
                    true => 'tame',
                    false => 'wild',
                    default => 'tameness unavailable',
                },
                self::valueOfMax($critter['age'] ?? null),
                self::valueOfMax($critter['calories'] ?? null),
            );
        }
        if ($critters === []) {
            $lines[] = ' none';
        }

        return $lines;
    }

    /** @return list<string> */
    private function elements(Zone $zone, GameText $text): array
    {
        if (! is_array($zone->slow_state)) {
            return ['slow state unavailable'];
        }

        $rows = ZoneTerrainSummary::byElement($zone->slow_state);
        $states = Element::query()->whereIn('element_id', array_column($rows, 'elementId'))->pluck('state', 'element_id');
        $lines = ['Elements (whole box):'];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                ' %s (%s) %s · %d cells · %s · %s',
                $text->name($row['elementId']),
                $row['elementId'],
                $states[$row['elementId']] ?? 'state unavailable',
                $row['cells'],
                McpFormat::kg($row['mass']),
                is_finite($row['minC']) ? McpFormat::celsius($row['minC']).($row['maxC'] - $row['minC'] >= 0.05 ? '..'.McpFormat::celsius($row['maxC']) : '') : 'temperature unavailable',
            );
        }

        return $lines;
    }

    /** @return list<string> */
    private function problems(Zone $zone): array
    {
        $lines = ['Problems:'];
        foreach ((array) ($zone->fast_state['plants'] ?? []) as $plant) {
            if (is_array($plant) && ($problems = ZoneProblems::forPlant($plant)) !== []) {
                $lines[] = sprintf(' plant %s#%s: %s', $plant['id'] ?? '?', $plant['instanceId'] ?? '?', implode('; ', $problems));
            }
        }
        [$buildings] = ZoneProblems::splitPassages(is_array($zone->fast_state['buildings'] ?? null) ? array_values(array_filter($zone->fast_state['buildings'], is_array(...))) : null);
        foreach ($buildings ?? [] as $building) {
            if (($problems = ZoneProblems::forBuilding($building)) !== []) {
                $lines[] = sprintf(' building %s#%s: %s', $building['prefabId'] ?? '?', $building['instanceId'] ?? '?', implode('; ', $problems));
            }
        }

        return count($lines) === 1 ? ['Problems: none'] : $lines;
    }
}
