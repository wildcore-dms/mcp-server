<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Core;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Device pollers: what WildCore collected from a device and when.
 */
final class Poller extends AbstractTools
{
    public const GROUP = 'poller';

    /**
     * Latest run of every poller on a device: status, start/finish time and error.
     * Shows why device data in WildCore may be outdated.
     *
     * @param int $device_id device ID
     */
    #[Requires(permissions: ['poller_info_by_device'])]
    #[McpTool(name: 'device_pollers', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function latest(int $device_id): CallToolResult
    {
        $list = $this->api->get(WildcoreClient::path('poller', 'latests', $device_id));

        return $this->out(array_map(static fn ($r) => \is_array($r) ? array_filter([
            'poller' => $r['poller'] ?? null,
            'status' => $r['status'] ?? null,
            'start_at' => $r['start_at'] ?? null,
            'stop_at' => $r['stop_at'] ?? null,
            'error' => isset($r['error']) ? self::error($r['error']) : null,
        ], static fn ($v) => null !== $v) : $r, \is_array($list) ? array_values($list) : []));
    }

    /**
     * Run all pollers of a device now to refresh its data in WildCore (reads the device, does not change it).
     * Runs in background by default; with background=false waits for the result.
     *
     * @param int  $device_id  device ID
     * @param bool $background run in background and return immediately
     */
    #[Requires(permissions: ['device_management'])]
    #[McpTool(name: 'device_poll', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    public function poll(int $device_id, bool $background = true): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('poller', $background ? 'poll-background' : 'poll', $device_id)));
    }
}
