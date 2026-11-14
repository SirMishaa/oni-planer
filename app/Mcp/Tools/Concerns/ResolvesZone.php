<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Colony;
use App\Models\Zone;
use App\Services\Mcp\McpColony;
use App\Services\Mcp\McpLookupException;
use App\Services\Mcp\McpZones;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** The required `zone` argument (id or name) and the colony it belongs to. */
trait ResolvesZone
{
    use ResolvesColony;

    protected function zoneSchema(JsonSchema $schema): Type
    {
        return $schema->string()->description('Zone id (from list_zones) or name; a unique fragment of the name works.')->required();
    }

    /**
     * @return array{0: Colony, 1: Zone}|Response the colony and zone, or the error to return
     */
    protected function colonyAndZone(Request $request): array|Response
    {
        $colony = $this->colony($request);
        if (! $colony instanceof Colony) {
            return Response::error(McpColony::NOT_SYNCED);
        }

        $zone = $request->get('zone');

        try {
            return [$colony, McpZones::resolve($colony, is_scalar($zone) ? (string) $zone : '')];
        } catch (McpLookupException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
