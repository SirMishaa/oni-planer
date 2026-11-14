<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesColony;
use App\Models\Colony;
use App\Models\UplinkCommand;
use App\Models\UplinkSession;
use App\Models\Zone;
use App\Services\GameText;
use App\Services\Mcp\McpColony;
use App\Services\Mcp\McpHeader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('colony_status')]
#[Description('Sync health of the colony (last message, session, age of each zone\'s data, Uplink commands) and a colony-wide summary: duplicants, food, critters and plants by species. Start here.')]
#[IsReadOnly]
final class ColonyStatusTool extends Tool
{
    use ResolvesColony;

    public function handle(Request $request, GameText $text): Response
    {
        $colony = $this->colony($request);

        if (! $colony instanceof Colony) {
            return Response::error(McpColony::NOT_SYNCED);
        }

        $lines = [McpHeader::line($colony), ''];
        $lines[] = sprintf('guid %s · game seconds %s', $colony->guid, $colony->game_seconds === null ? 'unavailable' : number_format($colony->game_seconds, 0, '.', ''));

        $session = UplinkSession::query()->where('colony_guid', $colony->guid)->latest('started_at')->first();
        $lines[] = $session instanceof UplinkSession
            ? sprintf('session started %s · loaded at cycle %d', McpHeader::ago($session->started_at), $session->cycle)
            : 'session unavailable';

        UplinkCommand::expireStale();
        $commands = UplinkCommand::query()->where('colony_guid', $colony->guid)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $lines[] = 'commands '.implode(', ', array_map(
            fn (string $status): string => sprintf('%s %d', $status, (int) ($commands[$status] ?? 0)),
            [UplinkCommand::PENDING, UplinkCommand::DONE, UplinkCommand::REFUSED, UplinkCommand::EXPIRED],
        ));

        $lines[] = '';
        $lines[] = 'Zones (data age: fast / slow / layout):';
        $zones = Zone::query()->active()->where('colony_guid', $colony->guid)->orderBy('name')
            ->get(['id', 'name', 'fast_state_at', 'slow_state_at', 'layout_at']);
        foreach ($zones as $zone) {
            $lines[] = sprintf(' #%d %s · %s / %s / %s', $zone->id, $zone->name, McpHeader::age($zone->fast_state_at), McpHeader::age($zone->slow_state_at), McpHeader::age($zone->layout_at));
        }
        if ($zones->isEmpty()) {
            $lines[] = ' none';
        }

        $state = $colony->state;
        $lines[] = '';
        $lines[] = is_array($state['duplicants'] ?? null) ? 'duplicants '.count($state['duplicants']) : 'duplicants unavailable';
        $lines[] = 'critters: '.$this->bySpecies($state['critters'] ?? null, $text);
        $lines[] = 'plants: '.$this->bySpecies($state['plants'] ?? null, $text);
        $lines[] = 'food: '.$this->food($state['food'] ?? null, $text);

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['colony' => $this->colonySchema($schema)];
    }

    /** "Pokeshell (Crab) 2, …" or "unavailable". */
    private function bySpecies(mixed $entities, GameText $text): string
    {
        if (! is_array($entities)) {
            return 'unavailable';
        }

        $counts = [];
        foreach ($entities as $entity) {
            $id = is_array($entity) && is_string($entity['id'] ?? null) ? $entity['id'] : '?';
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        arsort($counts);

        return $counts === [] ? 'none' : implode(', ', array_map(
            fn (string $id, int $count): string => sprintf('%s (%s) %d', $text->name($id), $id, $count),
            array_keys($counts),
            $counts,
        ));
    }

    /** "Mush Bar (MushBar) 4 u 3200 kcal, … · total N kcal" or "unavailable". */
    private function food(mixed $rows, GameText $text): string
    {
        if (! is_array($rows)) {
            return 'unavailable';
        }

        $total = 0.0;
        $parts = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }
            $kcal = is_numeric($row['calories'] ?? null) ? (float) $row['calories'] / 1000 : null;
            $total += $kcal ?? 0.0;
            $parts[] = sprintf(
                '%s (%s) %s u %s',
                $text->name($row['id']),
                $row['id'],
                is_numeric($row['units'] ?? null) ? round((float) $row['units'], 1) : '?',
                $kcal === null ? 'kcal unavailable' : round($kcal).' kcal',
            );
        }

        return $parts === [] ? 'none' : implode(', ', $parts).sprintf(' · total %d kcal', round($total));
    }
}
