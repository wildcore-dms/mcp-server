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
 * ICMP availability of devices (pinger component).
 */
#[Requires(permissions: ['device_show'])]
final class Pinger extends AbstractTools
{
    public const GROUP = 'pinger';
    public const COMPONENT = 'pinger';

    /**
     * Current ICMP status of one device and since when.
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'pinger_status', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function status(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'pinger', 'status', $device_id)));
    }

    /**
     * ICMP down history of a device: when it went down and came back.
     *
     * @param int $device_id device ID
     * @param int $limit     maximum number of records
     * @param int $offset    number of records to skip
     */
    #[McpTool(name: 'pinger_logs', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function logs(int $device_id, #[Schema(minimum: 1, maximum: 1000)] int $limit = 100, #[Schema(minimum: 0)] int $offset = 0): CallToolResult
    {
        return $this->out($this->paginate($this->api->get(WildcoreClient::path('component', 'pinger', 'logs', $device_id)), $limit, $offset));
    }

    /**
     * ICMP status of all devices. Filter by status to list devices that are down right now.
     * "status" in records is latency in ms: above 0 - up (999 - reachable by TCP only), 0 or below - down.
     *
     * @param string $status which devices to return
     * @param int    $limit  maximum number of devices
     * @param int    $offset number of devices to skip
     */
    #[McpTool(name: 'pinger_statuses', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function statuses(
        #[Schema(enum: ['all', 'down', 'up'])] string $status = 'down',
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 200,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get('component/pinger/statuses');
        $list = \is_array($list) && isset($list['data']) ? $list['data'] : $list;
        // Same rule as WildCore: latency above 0 - up
        $list = array_values(array_filter(\is_array($list) ? $list : [], static fn ($d) => 'all' === $status
            || ('up' === $status) === ((int) ($d['status'] ?? 0) > 0)));

        return $this->out($this->paginate($list, $limit, $offset));
    }
}
