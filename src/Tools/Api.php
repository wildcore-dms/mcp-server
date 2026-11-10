<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * Fallback for read requests not covered by dedicated tools.
 */
final class Api extends AbstractTools
{
    public const GROUP = 'api';

    /**
     * Send GET request to any WildCore API path relative to /api/v1 (e.g. "device-model",
     * "component/olts/cards/15"). Use only when no dedicated tool fits.
     *
     * @param string               $path  path relative to /api/v1, without query string
     * @param array<string, mixed> $query query parameters
     */
    #[McpTool(name: 'api_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(string $path, array $query = []): CallToolResult
    {
        $path = trim($path, '/');
        // Only a relative path inside /api/v1: no scheme, query, fragment or walking up
        if ('' === $path || preg_match('#[?\#\\\\]|://|(^|/)\.\.?(/|$)|//#', $path)) {
            throw new ToolCallException("Invalid path '$path': use a path relative to /api/v1 and pass query parameters in query");
        }

        return $this->out($this->api->get(implode('/', array_map('rawurlencode', explode('/', $path))), $query));
    }
}
