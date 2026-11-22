<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Zones\WriteZonePlan;
use App\Enums\ZonePlanChangeKind;
use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Services\Mcp\McpHeader;
use App\Services\Mcp\ZonePlanSummary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write_zone_plan')]
#[Description('Write the plan of a zone: the changes you propose (add, remove, replace), shown to the player as a variant of the zone. Replaces the zone\'s whole plan; an empty changes list clears it. Adds name an exact building id (lookup finds it) and the world cell of their bottom-left corner. Removes and replaces target "#instanceId" (a building, ids from zone_map) or "w(x,y)" (a tile). Each change needs a short note saying why. Checked changes (buildings and tiles) must fit: inside the zone box, a permitted rotation, no overlap with other buildings or tiles (the plan\'s other changes included), no solid cell, a floor or ceiling when the building needs one; any problem refuses the whole write, listed per change. Wires, pipes, logic and other layers the game does not send are stored unchecked. Read it back with zone_plan or zone_map plan: true.')]
final class WriteZonePlanTool extends Tool
{
    use ResolvesZone;

    private const array ORIENTATIONS = ['Neutral', 'R90', 'R180', 'R270', 'FlipH', 'FlipV'];

    public function handle(Request $request, WriteZonePlan $writePlan, ZonePlanSummary $summary): Response
    {
        $validated = $request->validate([
            'changes' => ['present', 'array', 'list', 'max:200'],
            'changes.*' => ['array'],
            'changes.*.kind' => ['required', 'string', 'in:'.implode(',', $this->kinds())],
            'changes.*.prefab' => ['nullable', 'string', 'max:100'],
            'changes.*.x' => ['nullable', 'integer'],
            'changes.*.y' => ['nullable', 'integer'],
            'changes.*.orientation' => ['nullable', 'string', 'in:'.implode(',', self::ORIENTATIONS)],
            'changes.*.target' => ['nullable', 'string', 'max:100'],
            'changes.*.note' => ['required', 'string', 'max:500'],
        ]);

        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        /** @var list<array{kind: string, prefab?: string|null, x?: int|null, y?: int|null, orientation?: string|null, target?: string|null, note: string}> $changes */
        $changes = array_values($validated['changes']);
        $result = $writePlan->handle($zone, $changes);

        if (is_array($result)) {
            return Response::error(sprintf("Plan of zone #%d refused, nothing stored:\n%s", $zone->id, implode("\n", $result)));
        }

        $lines = [McpHeader::line($colony, $zone), ''];
        if ($result === null) {
            $lines[] = sprintf('Plan of zone #%d cleared.', $zone->id);
        } else {
            $lines[] = sprintf('Plan of zone #%d written: %d %s.', $zone->id, count($changes), count($changes) === 1 ? 'change' : 'changes');
            array_push($lines, ...$summary->lines($zone, $result));
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'zone' => $this->zoneSchema($schema),
            'changes' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum($this->kinds())->description('add a building, remove one, or replace one (remove + add in one change).')->required(),
                'prefab' => $schema->string()->description('Exact building id to add (add, replace).'),
                'x' => $schema->integer()->description('World x of the bottom-left cell of the added building (add, replace).'),
                'y' => $schema->integer()->description('World y of the bottom-left cell, y up (add, replace).'),
                'orientation' => $schema->string()->enum(self::ORIENTATIONS)->description('Rotation of the added building; default Neutral.'),
                'target' => $schema->string()->description('What goes away (remove, replace): "#instanceId" for a building, "w(x,y)" for a tile.'),
                'note' => $schema->string()->description('Why this change, one short sentence for the player.')->required(),
            ]))->description('The whole plan, in order; replaces the current plan. Empty clears it.')->required(),
            'colony' => $this->colonySchema($schema),
        ];
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return array_map(fn (ZonePlanChangeKind $kind): string => $kind->value, ZonePlanChangeKind::cases());
    }
}
