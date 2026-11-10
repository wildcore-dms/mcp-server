<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Core;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\OpenApiCatalog;
use WildcoreMcp\Tools\AbstractTools;

/**
 * Generic access to the whole WildCore API, described by its OpenAPI spec: find an operation,
 * read its parameters and call it. Every call must match an operation of the spec.
 *
 * Access of an operation ("METHOD /path/{template}"):
 *  - denied - never callable here (dedicated tools with their own checks exist, or not for API clients);
 *  - secret - returns credentials, needs ENABLE_SECRETS=true;
 *  - read   - every GET except DEVICE_ACTIONS, plus READ_OPERATIONS (POST/PUT used as filters);
 *  - write  - everything else, only through api_call.
 */
final class Api extends AbstractTools
{
    public const GROUP = 'api';

    private const DENIED = [
        '#^\S+ /switcher-core/#',          // swc_read / swc_call check modules
        '#^\S+ /universal#',               // legacy dispatcher, may run anything
        '#^\S+ /(user/auth|logout|auth)(/|$)#',
        '#^\S+ /component/attachments/(object|thumb)/#', // binary content
    ];

    private const SECRET = [
        '#^GET /component/oxidized/data/config/#',
        '#^\S+ /device-access#',           // device credentials (SNMP communities, passwords)
        '#^\S+ /system/configuration$#',   // environment parameters incl. keys
    ];

    /** GET operations that act on equipment */
    private const DEVICE_ACTIONS = [
        '#^GET /component/switches/interfaces/\{[^}]+\}/cable_diag$#',
    ];

    /** POST/PUT operations that only read (filters and searches) */
    private const READ_OPERATIONS = [
        '#^PUT /device-interface/ont-list$#',
        '#^POST /logs/(actions|switcher-core/actions|poller/pollers|schedule/reports)$#',
        '#^POST /component/console/logs$#',
        '#^POST /component/events$#',
        '#^(POST|PUT) /component/analytics/(table|bars|charts|errors)/#',
        '#^POST /portal/(search|nearest-elements)$#',
        '#^PUT /component/links/view/list$#',
        '#^POST /component/fdb_history/filter$#',
        '#^POST /component/search_device/(search-ip|search-ip-with-fdb|search-mac)$#',
        '#^POST /component/trapservice/history$#',
        '#^POST /component/prometheus_wrapper/chart-[a-z-]+$#',
        '#^POST /component/sensor_devices/\{[^}]+\}/get-series/#',
        '#^POST /dev-dashboard/devices$#',
        '#^PUT /maps/(devices|device-links|onts)$#',
        '#^PUT /interface-marks/(favorite|tagged)/list$#',
        '#^POST /component/(macros|onts_registration)/(variables|control/preview|control/variables)$#',
    ];

    private ?OpenApiCatalog $catalog = null;

    /**
     * Search WildCore API operations by words in path, summary, tag or operation ID (all words must match).
     * Start here when no dedicated tool fits, then api_describe for parameters, then api_read / api_call.
     * Operations of disabled WildCore components are not listed.
     *
     * @param string      $query  words to search, e.g. "fdb history", "pinger logs", "port description"
     * @param string|null $method only this HTTP method
     * @param string|null $tag    only this OpenAPI tag (WildCore area or component, e.g. "events", "switches")
     * @param string      $access only operations with this access level
     * @param int         $limit  maximum number of operations
     */
    #[McpTool(name: 'api_search', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function search(
        string $query = '',
        #[Schema(enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])] ?string $method = null,
        ?string $tag = null,
        #[Schema(enum: ['any', 'read', 'write', 'secret'])] string $access = 'any',
        #[Schema(minimum: 1, maximum: 100)] int $limit = 30,
    ): CallToolResult {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($query))) ?: []);
        $found = [];
        $total = 0;
        foreach ($this->catalog()->operations() as $operation) {
            if ((null !== $method && $operation['method'] !== strtoupper($method))
                || (null !== $tag && 0 !== strcasecmp($operation['tag'], $tag))
                || !$this->componentEnabled($operation)) {
                continue;
            }
            $level = $this->access($operation);
            if ('denied' === $level || ('any' !== $access && $access !== $level)) {
                continue;
            }
            $haystack = mb_strtolower($operation['path'].' '.$operation['summary'].' '.$operation['tag'].' '.$operation['id']);
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            ++$total;
            if (\count($found) < $limit) {
                $found[] = [
                    'operation_id' => $operation['id'],
                    'method' => $operation['method'],
                    'path' => $operation['path'],
                    'summary' => $operation['summary'],
                    'tag' => $operation['tag'],
                    'access' => $level,
                ];
            }
        }

        return $this->out(['total' => $total, 'operations' => $found]);
    }

    /**
     * Describe one WildCore API operation: parameters, request body and response schema, access level
     * and which tool calls it (api_read for read, api_call for write).
     *
     * @param string $operation operation ID from api_search, or "METHOD /path" (template or concrete path)
     */
    #[McpTool(name: 'api_describe', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function describe(string $operation): CallToolResult
    {
        $catalog = $this->catalog();
        $found = $catalog->byId($operation);
        if (null === $found && preg_match('#^(GET|POST|PUT|PATCH|DELETE)\s+/?(\S+)$#i', trim($operation), $m)) {
            $found = $catalog->match($m[1], str_replace(['{', '}'], '', $m[2]));
        }
        $operation = $found ?? throw new ToolCallException("Unknown operation '$operation', find it with api_search");
        $level = $this->access($operation);

        return $this->out([
            'operation_id' => $operation['id'],
            'method' => $operation['method'],
            'path' => $operation['path'],
            'summary' => $operation['summary'],
            'description' => $operation['description'],
            'tag' => $operation['tag'],
            'component' => $operation['component'],
            'access' => $level,
            'call_with' => match ($level) {
                'read' => 'api_read',
                'write' => 'api_call',
                'secret' => $this->config->secretsEnabled ? ('GET' === $operation['method'] ? 'api_read' : 'api_call') : 'not available: ENABLE_SECRETS is off',
                default => 'not available through generic API tools',
            },
            'parameters' => array_map(static fn ($p) => $catalog->resolve($p), $operation['parameters']),
            'request_body' => $catalog->resolve($operation['requestBody']),
            'response' => $catalog->resolve($operation['responses']),
        ]);
    }

    /**
     * Call a read-only WildCore API operation (GET, or POST/PUT that only filters/searches).
     * The path must match an operation of the spec, see api_search / api_describe.
     *
     * @param string                    $path   path relative to /api/v1 with values instead of placeholders, e.g. "component/pinger/logs/15"
     * @param string                    $method HTTP method of the operation
     * @param array<string, mixed>      $query  query parameters
     * @param array<string, mixed>|null $body   JSON body for POST/PUT operations
     */
    #[McpTool(name: 'api_read', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function read(
        string $path,
        #[Schema(enum: ['GET', 'POST', 'PUT'])] string $method = 'GET',
        #[Schema(definition: [...self::OBJECT, 'description' => 'query parameters'])] array $query = [],
        #[Schema(definition: [...self::NULLABLE_OBJECT, 'description' => 'JSON body for POST/PUT/PATCH operations'])] ?array $body = null,
    ): CallToolResult {
        [$operation, $path, $level] = $this->resolveCall($method, $path);
        if ('read' !== $level && !('secret' === $level && 'GET' === $operation['method'])) {
            throw new ToolCallException(\sprintf('%s %s is not a read operation (access: %s) - use api_call', $operation['method'], $operation['path'], $level));
        }

        return $this->out($this->api->call($operation['method'], $path, $query, $body));
    }

    /**
     * Call any WildCore API operation, including ones that change devices or WildCore data
     * (create/update/delete, port and ONU control, reboot ...). Check the operation with api_describe,
     * show the user what will be changed and get explicit confirmation first.
     *
     * @param string                    $method HTTP method of the operation
     * @param string                    $path   path relative to /api/v1 with values instead of placeholders
     * @param array<string, mixed>      $query  query parameters
     * @param array<string, mixed>|null $body   JSON body
     */
    #[McpTool(name: 'api_call', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function call(
        #[Schema(enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])] string $method,
        string $path,
        #[Schema(definition: [...self::OBJECT, 'description' => 'query parameters'])] array $query = [],
        #[Schema(definition: [...self::NULLABLE_OBJECT, 'description' => 'JSON body for POST/PUT/PATCH operations'])] ?array $body = null,
    ): CallToolResult {
        [$operation, $path] = $this->resolveCall($method, $path);

        return $this->out($this->api->call($operation['method'], $path, $query, $body));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string} operation, normalized path, access level
     */
    private function resolveCall(string $method, string $path): array
    {
        $path = trim($path, '/');
        // Only a relative path inside /api/v1: no scheme, query, fragment or walking up
        if ('' === $path || preg_match('#[?\#\\\\]|://|(^|/)\.\.?(/|$)|//#', $path)) {
            throw new ToolCallException("Invalid path '$path': use a path relative to /api/v1 and pass query parameters in query");
        }
        $operation = $this->catalog()->match($method, $path)
            ?? throw new ToolCallException(\sprintf('No WildCore API operation %s /%s - find it with api_search', strtoupper($method), $path));
        if (!$this->componentEnabled($operation)) {
            throw new ToolCallException("WildCore component '{$operation['component']}' is not enabled");
        }
        $level = $this->access($operation);
        if ('denied' === $level) {
            throw new ToolCallException(\sprintf('%s %s is not available through generic API tools%s', $operation['method'], $operation['path'], str_contains($operation['path'], '/switcher-core/') ? ' - use swc_read / swc_call' : ''));
        }
        if ('secret' === $level && !$this->config->secretsEnabled) {
            throw new ToolCallException(\sprintf('%s %s returns credentials and is disabled (ENABLE_SECRETS=false)', $operation['method'], $operation['path']));
        }

        return [$operation, implode('/', array_map('rawurlencode', explode('/', $path))), $level];
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function access(array $operation): string
    {
        $key = $operation['method'].' '.$operation['path'];
        if ('auth' === $operation['tag'] || self::matches(self::DENIED, $key)) {
            return 'denied';
        }
        if (self::matches(self::SECRET, $key)) {
            return 'secret';
        }
        if ('GET' === $operation['method']) {
            return self::matches(self::DEVICE_ACTIONS, $key) ? 'write' : 'read';
        }

        return self::matches(self::READ_OPERATIONS, $key) ? 'read' : 'write';
    }

    /**
     * @param list<string> $patterns
     */
    private static function matches(array $patterns, string $key): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function componentEnabled(array $operation): bool
    {
        return null === $operation['component'] || null === $this->capabilities
            || \in_array($operation['component'], $this->capabilities['components'], true);
    }

    private function catalog(): OpenApiCatalog
    {
        return $this->catalog ??= new OpenApiCatalog($this->api, $this->config);
    }
}
