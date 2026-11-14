<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Uplink\SendUplinkCommand;
use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Services\Mcp\McpHeader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('request_capture')]
#[Description('Ask the game, through the Uplink mod, to render a new picture of a zone (same as the capture button of the zone page). Returns the command id; follow it with zone_captures (last request status, then the new capture).')]
#[IsIdempotent(false)]
final class RequestCaptureTool extends Tool
{
    use ResolvesZone;

    public function handle(Request $request, SendUplinkCommand $sendCommand): Response
    {
        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $command = $sendCommand->handle($zone->colony_guid, 'zone.capture', ['zoneId' => $zone->zone_guid]);
        $seconds = $colony->last_seen_at === null ? null : (int) $colony->last_seen_at->diffInSeconds(now(), true);

        $lines = [
            McpHeader::line($colony, $zone),
            '',
            sprintf('Capture of #%d %s requested: command %s %s, expires %s.', $zone->id, $zone->name, $command->id, $command->status, $command->expires_at->toDateTimeString()),
        ];
        if ($seconds === null || $seconds > McpHeader::OFFLINE_SECONDS) {
            $lines[] = sprintf('The game looks offline (last seen %s): the command will likely expire unanswered.', McpHeader::ago($colony->last_seen_at));
        }
        $lines[] = 'Follow it with zone_captures.';

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['zone' => $this->zoneSchema($schema), 'colony' => $this->colonySchema($schema)];
    }
}
