<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Switch control (switches_control component). Every tool changes the device -
 * show the change to the user and get explicit confirmation first.
 */
final class SwitchesControl extends AbstractTools
{
    public const GROUP = 'switches_control';
    public const COMPONENT = 'switches_control';

    /**
     * Change port description, admin state and/or speed. Only passed values are applied; each needs its own
     * WildCore permission (port description / admin state / admin speed).
     *
     * @param int         $device_id   switch device ID
     * @param string      $interface   interface bind key or name
     * @param string|null $description new description
     * @param string|null $admin_state enable or disable the port
     * @param string|null $admin_speed admin speed/duplex, e.g. "auto", "100-Full", "1000-Full"
     */
    #[Requires(permissions: ['switches_set_port_description', 'switches_set_port_admin_state', 'switches_set_port_admin_speed'])]
    #[McpTool(name: 'switch_port_set', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function portSet(
        int $device_id,
        #[Schema(minLength: 1)] string $interface,
        ?string $description = null,
        #[Schema(enum: ['enable', 'disable'])] ?string $admin_state = null,
        ?string $admin_speed = null,
    ): CallToolResult {
        $changes = array_filter(compact('description', 'admin_state', 'admin_speed'), static fn ($v) => null !== $v);
        if (!$changes) {
            throw new ToolCallException('Nothing to change: pass description, admin_state or admin_speed');
        }

        return $this->out($this->api->put(WildcoreClient::path('component', 'switches_control', 'interface', $device_id, $interface), $changes));
    }

    /**
     * Clear counters of the whole switch or of one port.
     *
     * @param int         $device_id switch device ID
     * @param string|null $interface only this interface (bind key or name)
     */
    #[Requires(permissions: ['switches_clear_counters'])]
    #[McpTool(name: 'switch_clear_counters', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function clearCounters(int $device_id, ?string $interface = null): CallToolResult
    {
        $path = null === $interface
            ? WildcoreClient::path('component', 'switches_control', 'system', $device_id, 'clear_counters')
            : WildcoreClient::path('component', 'switches_control', 'system', $device_id, $interface, 'clear_counters');

        return $this->out($this->api->put($path));
    }

    /**
     * Save running configuration of the switch.
     *
     * @param int $device_id switch device ID
     */
    #[Requires(permissions: ['switches_save_config'])]
    #[McpTool(name: 'switch_save_config', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true))]
    public function saveConfig(int $device_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('component', 'switches_control', 'system', $device_id, 'save_config')));
    }

    /**
     * Reboot the switch - every subscriber behind it loses service until it boots.
     *
     * @param int $device_id switch device ID
     */
    #[Requires(permissions: ['switches_reboot_device'])]
    #[McpTool(name: 'switch_reboot', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function reboot(int $device_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('component', 'switches_control', 'system', $device_id, 'reboot')));
    }
}
