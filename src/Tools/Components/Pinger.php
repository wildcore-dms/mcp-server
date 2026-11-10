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
     * Current ICMP state of one device (up/down), percent of answered pings, availability for 1/7/30 days,
     * since when it is in the current state (duration in seconds).
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'pinger_status', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function status(int $device_id): CallToolResult
    {
        $status = $this->api->get(WildcoreClient::path('component', 'pinger', 'status', $device_id));
        if (\is_array($status) && \array_key_exists('raw_status', $status)) {
            $status = self::describe((int) $status['raw_status']) + $status;
            unset($status['raw_status'], $status['status']);
        }

        return $this->out($status);
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
     * ICMP state of all devices: state up/down and percent of answered pings. Filter by status to list devices
     * that are down right now.
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
        $list = array_map(static fn ($d) => ['id' => $d['id'] ?? null, 'ip' => $d['ip'] ?? null] + self::describe((int) ($d['status'] ?? 0)), \is_array($list) ? array_values(array_filter($list, 'is_array')) : []);
        $list = array_values(array_filter($list, static fn ($d) => 'all' === $status || $d['state'] === $status));

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * WildCore pinger value: percent of answered pings (1..100) - the device is up; 999 - answers only TCP checks;
     * 0 or below - down / not checked yet. Turned into explicit fields so the raw number needs no interpretation.
     *
     * @return array{state: string, reply_percent?: int, tcp_only?: bool}
     */
    private static function describe(int $value): array
    {
        if (999 === $value) {
            return ['state' => 'up', 'tcp_only' => true];
        }

        return $value > 0 ? ['state' => 'up', 'reply_percent' => min($value, 100)] : ['state' => 'down'];
    }
}
