<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesColony;
use App\Models\Colony;
use App\Models\Zone;
use App\Services\GameText;
use App\Services\Mcp\McpColony;
use App\Services\Mcp\McpFormat;
use App\Services\Mcp\McpHeader;
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

#[Name('entity')]
#[Description('Everything the game sent about one plant or building, by its instance id (from zone_map or zone_overview): position, size, state, status items, storage, growth, problems, the zones containing it. Critters have no id yet.')]
#[IsReadOnly]
final class EntityTool extends Tool
{
    use ResolvesColony;

    public function handle(Request $request, GameText $text): Response
    {
        $request->validate(['instanceId' => ['required', 'string']]);
        $instanceId = (string) $request->get('instanceId');

        $colony = $this->colony($request);
        if (! $colony instanceof Colony) {
            return Response::error(McpColony::NOT_SYNCED);
        }

        $zones = Zone::query()->active()->where('colony_guid', $colony->guid)->orderBy('id')->get();
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
            $found ??= $plant !== null ? ['plant', $plant] : ($building !== null ? ['building', $building] : null);
            $layoutEntry ??= $layout;
            $gridWidth = $gridWidth ?: (int) ($zone->layout['gridWidth'] ?? 0);
        }

        if ($found === null && $layoutEntry === null) {
            return Response::error(sprintf('No plant or building #%s in the zones of colony "%s" (critters have no id yet: find them in zone_overview).', $instanceId, $colony->name));
        }

        [$kind, $entity] = $found ?? ['building', []];
        $prefabId = (string) ($entity['id'] ?? $entity['prefabId'] ?? $layoutEntry['prefabId'] ?? '?');
        $lines = [McpHeader::line($colony), '', sprintf('%s %s (%s) #%s', $kind, $text->name($prefabId), $prefabId, $instanceId)];

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
                $lines[] = sprintf('%s: %s', $key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : var_export($value, true));
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
        $lines[] = 'problems: '.($problems === [] ? 'none' : implode('; ', $problems));
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
            'instanceId' => $schema->string()->description('Instance id of a plant or building, as zone_map and zone_overview print it after #.')->required(),
            'colony' => $this->colonySchema($schema),
        ];
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
}
