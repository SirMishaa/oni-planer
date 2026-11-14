<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesZone;
use App\Models\UplinkCommand;
use App\Models\Zone;
use App\Models\ZoneCapture;
use App\Services\Mcp\McpHeader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zone_captures')]
#[Description('The pictures the game rendered of a zone (WebP, newest first, last 15 kept): without capture, the list (id, time, cycle, trigger, size, whether the layout changed since) plus the last capture request and the app page URL (open it with a browser tool to see the planner\'s own rendering). With capture (id, "latest" or "previous"): that picture as an image, to check a before / after.')]
#[IsReadOnly]
final class ZoneCapturesTool extends Tool
{
    use ResolvesZone;

    public function handle(Request $request): Response|ResponseFactory
    {

        $resolved = $this->colonyAndZone($request);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$colony, $zone] = $resolved;

        $captures = $zone->captures()->get();
        $wanted = is_scalar($request->get('capture')) ? (string) $request->get('capture') : null;

        if (! is_string($wanted) || $wanted === '') {
            return Response::text(implode("\n", [McpHeader::line($colony, $zone), '', ...$this->listing($zone, $captures->all())]));
        }

        $capture = match ($wanted) {
            'latest' => $captures->get(0),
            'previous' => $captures->get(1),
            default => $captures->firstWhere('id', (int) $wanted),
        };

        if (! $capture instanceof ZoneCapture) {
            return Response::error(sprintf('No capture "%s" for zone #%d (%d captures): call zone_captures without capture to list them.', $wanted, $zone->id, $captures->count()));
        }

        if (! Storage::disk('public')->exists($capture->path)) {
            return Response::error(sprintf('Capture #%d of zone #%d: file missing (%s).', $capture->id, $zone->id, $capture->path));
        }

        return Response::make([
            Response::text($this->line($zone, $capture)),
            Response::fromStorage($capture->path, disk: 'public', mimeType: 'image/webp'),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'zone' => $this->zoneSchema($schema),
            'capture' => $schema->string()->description('Capture id, "latest" or "previous"; omit to list the captures.'),
            'colony' => $this->colonySchema($schema),
        ];
    }

    /**
     * @param  list<ZoneCapture>  $captures
     * @return list<string>
     */
    private function listing(Zone $zone, array $captures): array
    {
        $lines = [sprintf('Captures of #%d %s (newest first):', $zone->id, $zone->name)];
        foreach ($captures as $capture) {
            $lines[] = ' '.$this->line($zone, $capture);
        }
        if ($captures === []) {
            $lines[] = ' none yet: request_capture asks the game for one.';
        }

        $request = UplinkCommand::query()
            ->where('colony_guid', $zone->colony_guid)
            ->where('type', 'zone.capture')
            ->where('payload->zoneId', $zone->zone_guid)
            ->latest('issued_at')
            ->first();
        $lines[] = $request instanceof UplinkCommand
            ? sprintf('Last request: %s %s, issued %s%s', $request->id, $request->status, McpHeader::ago($request->issued_at), $request->reason === null ? '' : ' ('.$request->reason.')')
            : 'Last request: none';
        $lines[] = 'App page: '.route('zones.show', $zone);

        return $lines;
    }

    private function line(Zone $zone, ZoneCapture $capture): string
    {
        $signature = $zone->layout['signature'] ?? null;

        return sprintf(
            '#%d %s · cycle %d · %s · %dx%d · layout changed since: %s',
            $capture->id,
            $capture->taken_at->toDateTimeString(),
            $capture->cycle(),
            $capture->trigger,
            $capture->width,
            $capture->height,
            ! is_string($signature) || $capture->layout_signature === null ? 'unknown' : ($capture->layout_signature === $signature ? 'no' : 'yes'),
        );
    }
}
