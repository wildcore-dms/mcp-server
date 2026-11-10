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
 * ONU and OLT port control (olts_control component). Every tool changes the OLT -
 * show the change to the user and get explicit confirmation first.
 * "bind_key" is the interface bind key (interface.bind_key from onts_list / device_interfaces).
 */
final class OltsControl extends AbstractTools
{
    public const GROUP = 'olts_control';
    public const COMPONENT = 'olts_control';

    /**
     * Reboot an ONU - the subscriber loses service for a few minutes.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key
     */
    #[Requires(permissions: ['olts_ctrl_reboot'])]
    #[McpTool(name: 'ont_reboot', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function reboot(int $device_id, #[Schema(minLength: 1)] string $bind_key): CallToolResult
    {
        return $this->out($this->put('ont', 'reboot', $device_id, $bind_key));
    }

    /**
     * Enable or disable an ONU on the OLT (disabled ONU gives no service).
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key
     * @param string $state     enable or disable
     */
    #[Requires(permissions: ['olts_ctrl_disable'])]
    #[McpTool(name: 'ont_set_state', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true))]
    public function setState(int $device_id, #[Schema(minLength: 1)] string $bind_key, #[Schema(enum: ['enable', 'disable'])] string $state): CallToolResult
    {
        return $this->out($this->put('ont', 'disable', $device_id, $bind_key, ['state' => $state]));
    }

    /**
     * Set ONU description on the OLT.
     *
     * @param int    $device_id   OLT device ID
     * @param string $bind_key    ONU interface bind key
     * @param string $description new description
     */
    #[Requires(permissions: ['olts_ctrl_description'])]
    #[McpTool(name: 'ont_set_description', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true))]
    public function setDescription(int $device_id, #[Schema(minLength: 1)] string $bind_key, string $description): CallToolResult
    {
        return $this->out($this->put('ont', 'description', $device_id, $bind_key, compact('description')));
    }

    /**
     * Enable or disable a UNI (ethernet) port on an ONU.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key
     * @param int    $port      UNI port number on the ONU
     * @param string $state     enable or disable
     */
    #[Requires(permissions: ['olts_ctrl_uni_ports'])]
    #[McpTool(name: 'ont_uni_port_set', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true))]
    public function uniPort(int $device_id, #[Schema(minLength: 1)] string $bind_key, #[Schema(minimum: 1)] int $port, #[Schema(enum: ['enable', 'disable'])] string $state): CallToolResult
    {
        return $this->out($this->put('ont', 'uni-control', $device_id, $bind_key, ['action' => 'admin_state', 'num' => $port, 'state' => $state]));
    }

    /**
     * Reset ONU configuration to factory defaults - the ONU must be configured again afterwards.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key
     */
    #[Requires(permissions: ['olts_ctrl_reset'])]
    #[McpTool(name: 'ont_reset', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function reset(int $device_id, #[Schema(minLength: 1)] string $bind_key): CallToolResult
    {
        return $this->out($this->put('ont', 'reset', $device_id, $bind_key));
    }

    /**
     * Deregister (delete) an ONU from the OLT - it becomes unregistered and loses service.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key
     */
    #[Requires(permissions: ['olts_ctrl_dereg'])]
    #[McpTool(name: 'ont_deregister', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function deregister(int $device_id, #[Schema(minLength: 1)] string $bind_key): CallToolResult
    {
        return $this->out($this->put('ont', 'dereg', $device_id, $bind_key));
    }

    /**
     * Reset (bounce) an OLT port - every ONU on a PON port reconnects.
     *
     * @param int    $device_id OLT device ID
     * @param string $interface port bind key or name
     */
    #[Requires(permissions: ['olts_ctrl_port'])]
    #[McpTool(name: 'olt_port_reset', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function portReset(int $device_id, #[Schema(minLength: 1)] string $interface): CallToolResult
    {
        return $this->out($this->put('olt', 'reset-port', $device_id, $interface));
    }

    /**
     * Set description of an OLT port.
     *
     * @param int    $device_id   OLT device ID
     * @param string $interface   port bind key or name
     * @param string $description new description
     */
    #[Requires(permissions: ['olts_ctrl_port'])]
    #[McpTool(name: 'olt_port_set_description', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true))]
    public function portDescription(int $device_id, #[Schema(minLength: 1)] string $interface, string $description): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('component', 'olts_control', 'olt', 'interface', 'description', $device_id, $interface), compact('description')));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $object, string $action, int $device_id, string $interface, array $body = []): mixed
    {
        return $this->api->put(WildcoreClient::path('component', 'olts_control', $object, $action, $device_id, $interface), $body);
    }
}
