<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Colony;
use App\Services\Mcp\McpColony;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;

/** The optional `colony` argument every tool takes: a colony guid, defaulting to the last colony seen. */
trait ResolvesColony
{
    protected function colonySchema(JsonSchema $schema): Type
    {
        return $schema->string()->description('Colony guid; defaults to the colony the game spoke to last.');
    }

    protected function colony(Request $request): ?Colony
    {
        $guid = $request->get('colony');

        return McpColony::resolve(is_string($guid) ? $guid : null);
    }

    /** Why no colony resolved: nothing synced yet, or no colony with the guid asked for. */
    protected function colonyMissing(Request $request): string
    {
        $guid = $request->get('colony');

        return is_string($guid) && $guid !== '' ? McpColony::unknown($guid) : McpColony::NOT_SYNCED;
    }
}
