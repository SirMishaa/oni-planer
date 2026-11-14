<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Exceptions\McpLookupException;
use App\Models\Colony;
use App\Models\Zone;
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
        $request->validate(['rect' => [$required ? 'required' : 'nullable', 'array']]);
        $rect = $request->get('rect');
        if ($rect === null) {
            return null;
        }

        $request->validate([
            'rect.x' => ['required', 'integer'],
            'rect.y' => ['required', 'integer'],
            'rect.width' => ['required', 'integer', 'min:1'],
            'rect.height' => ['required', 'integer', 'min:1'],
        ]);

        return ['x' => (int) $rect['x'], 'y' => (int) $rect['y'], 'width' => (int) $rect['width'], 'height' => (int) $rect['height']];
    }

    /**
     * @return array{0: Colony, 1: Zone}|Response the colony and zone, or the error to return
     */
    protected function colonyAndZone(Request $request): array|Response
    {
        $colony = $this->colony($request);
        if (! $colony instanceof Colony) {
            return Response::error($this->colonyMissing($request));
        }

        $zone = $request->get('zone');

        try {
            return [$colony, McpZones::resolve($colony, is_scalar($zone) ? (string) $zone : '')];
        } catch (McpLookupException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
