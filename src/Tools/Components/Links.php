<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Network topology: links between devices (links component).
 */
#[Requires(permissions: ['links_view'])]
final class Links extends AbstractTools
{
    public const GROUP = 'links';
    public const COMPONENT = 'links';

    /**
     * Links of a device or of one interface: which device and port is connected where.
     *
     * @param int|null    $device_id    device ID
     * @param string|null $bind_key     with device_id - only links of this interface (bind key)
     * @param int|null    $interface_id instead of device_id - links of this interface ID
     */
    #[McpTool(name: 'device_links', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function byDevice(?int $device_id = null, ?string $bind_key = null, ?int $interface_id = null): CallToolResult
    {
        $path = match (true) {
            null !== $interface_id => WildcoreClient::path('component', 'links', 'by-interface', $interface_id),
            null !== $device_id && null !== $bind_key => WildcoreClient::path('component', 'links', 'by-device', $device_id, $bind_key),
            null !== $device_id => WildcoreClient::path('component', 'links', 'by-device', $device_id),
            default => throw new \Mcp\Exception\ToolCallException('Pass device_id (optionally with bind_key) or interface_id'),
        };

        return $this->out($this->api->get($path));
    }

    /**
     * Upward topology of a device: the chain of devices up to the core. Use it to find the common uplink
     * when several devices are down.
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'topology_upward', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function upward(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'links', 'upward', $device_id)));
    }
}
