<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Router data through switcher-core (routers component).
 */
#[Requires(permissions: ['router_info'])]
final class Routers extends AbstractTools
{
    public const GROUP = 'routers';
    public const COMPONENT = 'routers';

    private const TABLES = [
        'interfaces' => 'interfaces',
        'arp' => 'arps',
        'fdb' => 'fdb',
        'vlans' => 'vlans',
        'bgp_peers' => 'bgp-peers',
        'direct_routes' => 'direct-routes',
        'resources' => 'resources',
    ];

    /**
     * Read a router table: interfaces, ARP, FDB, VLANs, BGP peers, directly connected routes or resources.
     * ARP and FDB of a core router can be large - use interface or limit/offset.
     *
     * @param int         $device_id router device ID
     * @param string      $table     what to read
     * @param string|null $interface only this interface (for "interfaces")
     * @param string      $source    cache - cached data refreshed if stale; store - only cache; device - live request
     * @param int         $limit     maximum number of rows for list results
     * @param int         $offset    number of rows to skip
     */
    #[McpTool(name: 'router_table', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function table(
        int $device_id,
        #[Schema(enum: ['interfaces', 'arp', 'fdb', 'vlans', 'bgp_peers', 'direct_routes', 'resources'])] string $table,
        ?string $interface = null,
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
        #[Schema(minimum: 1, maximum: 2000)] int $limit = 200,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $data = $this->api->get(
            WildcoreClient::path('component', 'routers', self::TABLES[$table] ?? 'interfaces', $device_id),
            ['from' => $this->source($source), 'interface' => $interface],
        );

        return $this->out(\is_array($data) && array_is_list($data) ? $this->paginate($data, $limit, $offset) : $data);
    }
}
