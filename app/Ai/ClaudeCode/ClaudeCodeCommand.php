<?php

declare(strict_types=1);

namespace App\Ai\ClaudeCode;

use RuntimeException;

/**
 * Builds the argument list of a read-only headless `claude` run. The flags are a security
 * boundary: only the configured MCP server and tools are reachable, and anything not allowed
 * is denied instead of prompting.
 */
final class ClaudeCodeCommand
{
    /**
     * @param  array<string, mixed>  $config  The `ai.providers.claude-code` configuration.
     * @return list<string>
     */
    public static function arguments(
        array $config,
        string $model,
        ?string $instructions,
        string $prompt,
        string $sessionId,
        bool $resume,
    ): array {
        // The prompt follows -p directly: the variadic --tools/--allowedTools options would swallow a trailing positional.
        $arguments = [
            '-p',
            str_starts_with($prompt, '-') ? ' '.$prompt : $prompt,
            '--output-format', 'stream-json',
            '--verbose',
            '--model', $model,
            $resume ? '--resume' : '--session-id', $sessionId,
            '--permission-mode', 'dontAsk',
            '--strict-mcp-config',
            '--mcp-config', self::mcpConfig($config),
            '--tools', 'Read,Grep,Glob,Bash',
        ];

        if ($instructions !== null && $instructions !== '') {
            array_push($arguments, '--append-system-prompt', $instructions);
        }

        if (($config['allowed_tools'] ?? []) !== []) {
            array_push($arguments, '--allowedTools', ...$config['allowed_tools']);
        }

        if (($config['denied_tools'] ?? []) !== []) {
            array_push($arguments, '--disallowedTools', ...$config['denied_tools']);
        }

        return $arguments;
    }

    /**
     * A JSON string holding only the configured MCP server, taken from the project's MCP file.
     *
     * @param  array<string, mixed>  $config
     */
    private static function mcpConfig(array $config): string
    {
        $server = $config['mcp_server'];
        $servers = json_decode((string) file_get_contents($config['mcp_config']), true)['mcpServers'] ?? [];

        if (! isset($servers[$server])) {
            throw new RuntimeException("MCP server [{$server}] is not defined in {$config['mcp_config']}.");
        }

        return json_encode(['mcpServers' => [$server => $servers[$server]]], JSON_THROW_ON_ERROR);
    }
}
