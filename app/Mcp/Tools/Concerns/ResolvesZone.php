<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\McpLookupException;
use App\Models\Colony;
use App\Models\Zone;
use App\Services\Mcp\McpColony;
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

    protected function rectSchema(JsonSchema $schema, string $description): Type
    {
        return $schema->object([
            'x' => $schema->integer()->required(),
            'y' => $schema->integer()->required(),
            'width' => $schema->integer()->min(1)->required(),
            'height' => $schema->integer()->min(1)->required(),
        ])->description($description);
    }

    /**
     * The validated `rect` argument (world coordinates, bottom-left origin), or null when absent.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    protected function rect(Request $request, bool $required = false): ?array
    {
        $request->validate([
            'rect' => [$required ? 'required' : 'nullable', 'array'],
            'rect.x' => ['required_with:rect', 'integer'],
            'rect.y' => ['required_with:rect', 'integer'],
            'rect.width' => ['required_with:rect', 'integer', 'min:1'],
            'rect.height' => ['required_with:rect', 'integer', 'min:1'],
        ]);
        $rect = $request->get('rect');

        return is_array($rect) ? ['x' => (int) $rect['x'], 'y' => (int) $rect['y'], 'width' => (int) $rect['width'], 'height' => (int) $rect['height']] : null;
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
