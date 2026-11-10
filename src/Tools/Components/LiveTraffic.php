<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;

/**
 * Current traffic on an interface (live_traffic component).
 */
#[Requires(permissions: ['live_traffic_info'])]
final class LiveTraffic extends AbstractTools
{
    public const GROUP = 'live_traffic';
    public const COMPONENT = 'live_traffic';

    /**
     * Current in/out rate and counters of an interface, read live from the device.
     * Pass interface_id, or device_id with the interface bind key.
     *
     * @param int|null    $interface_id interface ID
     * @param int|null    $device_id    device ID (with bind_key)
     * @param string|null $bind_key     interface bind key (with device_id)
     */
    #[McpTool(name: 'interface_live_traffic', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function view(?int $interface_id = null, ?int $device_id = null, ?string $bind_key = null): CallToolResult
    {
        return $this->out($this->api->get('component/live_traffic/view', ['interface_id' => $interface_id, 'device_id' => $device_id, 'interface' => $bind_key]));
    }
}
