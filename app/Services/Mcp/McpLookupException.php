<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use RuntimeException;

/** Something an MCP call names (zone, capture, entity) does not resolve; the message is shown to the assistant as is. */
final class McpLookupException extends RuntimeException {}
