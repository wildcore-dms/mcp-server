<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\WildcoreClient;

#[Requires(permissions: ['device_show'])]
final class Devices extends AbstractTools
{
    public const GROUP = 'devices';

    /**
     * List devices (switches, OLTs, routers) available to the user, paginated by WildCore.
     *
     * @param int $page  page number, starting from 1
     * @param int $limit devices per page
     */
    #[McpTool(name: 'devices_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(
        #[Schema(minimum: 1)] int $page = 1,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
    ): CallToolResult {
        return $this->out($this->api->get('device', ['page' => $page, 'limit' => $limit]));
    }

    /**
     * Get device by ID with latest poller statuses and interfaces statistic.
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'device_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('device', $device_id)));
    }

    /**
     * Find device by its management IP address.
     *
     * @param string $ip device IP address
     */
    #[McpTool(name: 'device_find_by_ip', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function findByIp(#[Schema(format: 'ipv4')] string $ip): CallToolResult
    {
        return $this->out($this->api->get('device', ['ip' => $ip]));
    }

    /**
     * Global search over devices and interfaces: device name/IP, interface name or description,
     * agreement, ONU identifier (serial/MAC), FDB history. Prefix query with # to search by interface tag.
     *
     * @param string      $query  search string
     * @param string|null $filter limit search to one scope
     */
    #[Requires(permissions: ['global_search'])]
    #[McpTool(name: 'search', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function search(
        #[Schema(minLength: 1)] string $query,
        #[Schema(enum: ['agreement', 'description', 'interface', 'ont_ident', 'fdb_history', 'device'])] ?string $filter = null,
    ): CallToolResult {
        return $this->out($this->api->get('portal/search', ['query' => $query, 'filter' => $filter]));
    }

    /**
     * List device groups. Users see only devices of groups assigned to them.
     */
    #[Requires(permissions: ['system_info'])]
    #[McpTool(name: 'device_groups_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function groups(): CallToolResult
    {
        return $this->out($this->api->get('device-group'));
    }
}
