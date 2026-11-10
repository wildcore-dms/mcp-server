<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\WildcoreClient;

/**
 * Calls of switcher-core modules through WildCore API.
 * swc_read accepts only modules from READ_MODULES; anything else, including
 * modules added in future switcher-core versions, goes through swc_call.
 */
#[Requires(rootOnly: true)]
final class SwitcherCore extends AbstractTools
{
    public const GROUP = 'switcher-core';

    /**
     * Modules known to only read data. Not by prefix: reboot, save_config, clear_counters,
     * console_command, cable_diag, arp_ping, *_ctrl and ctrl_* change state of device.
     */
    public const READ_MODULES = [
        'system', 'sys_resources', 'sys_temp', 'supported_modules', 'parse_interface',
        'link_info', 'counters', 'errors', 'interface_counters', 'interface_descriptions', 'interface_info',
        'interface_rate_limits', 'interface_vlan_info', 'interfaces_list', 'interfaces_status', 'rmon',
        'fdb', 'arp_info', 'arps', 'lldp_info', 'vlans', 'vlan_list', 'vlans_by_port', 'pvid',
        'ddm_info', 'sfp_info', 'sfp_media', 'sfp_optical', 'slot_info', 'card_list', 'card_status',
        'bgp_info', 'bgp_peers', 'bgp_sessions', 'bgp_peer_routes', 'direct_routes', 'net_settings',
        'acl_ports_info', 'acl_v4_info', 'acl_v6_info', 'address_list_info', 'simple_queue_info',
        'dhcp_server_info', 'lease_info', 'snooping_info', 'power_status', 'power_sensor_state',
        'power_control_output_list', 'knock_sensor_state', 'analog_lines_list', 'digital_lines_list',
        'pon_count_registered_onts', 'pon_fdb', 'pon_interface_counters', 'pon_onts_blacklist',
        'pon_onts_configuration', 'pon_onts_down_history', 'pon_onts_mac_addr', 'pon_onts_optical',
        'pon_onts_reasons', 'pon_onts_serial', 'pon_onts_service_profile', 'pon_onts_status',
        'pon_ont_state', 'pon_onts_vendor', 'pon_ports_list', 'pon_ports_optical', 'pon_profiles',
        'gpon_profiles', 'onu_ip_host', 'unregistered_onts', 'uni_interfaces_counters',
        'uni_interfaces_status', 'uni_interfaces_vlans', 'zte_ont_configuration', 'zte_ont_info',
    ];

    /**
     * List switcher-core modules supported by device model.
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'swc_modules', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function modules(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('switcher-core', $device_id, 'modules')));
    }

    /**
     * Call a read-only switcher-core module on device, e.g. link_info, fdb, pon_onts_optical, snooping_info.
     * Arguments depend on module, most accept "interface" (interface name) to narrow the result.
     *
     * @param int                  $device_id device ID
     * @param string               $module    module name, see swc_modules
     * @param array<string, mixed> $arguments module arguments
     * @param string               $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'swc_read', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function read(
        int $device_id,
        #[Schema(enum: self::READ_MODULES)] string $module,
        array $arguments = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        if (!\in_array($module, self::READ_MODULES, true)) {
            throw new ToolCallException("Module '$module' is not a known read-only module, use swc_call");
        }

        return $this->out($this->api->post(WildcoreClient::path('switcher-core', $this->source($source), $module, $device_id), $arguments));
    }

    /**
     * Call any switcher-core module on device, including ones that change device state
     * (ctrl_*, reboot, save_config, clear_counters, console_command ...). Always requested live from device.
     *
     * @param int                  $device_id device ID
     * @param string               $module    module name, see swc_modules
     * @param array<string, mixed> $arguments module arguments
     */
    #[McpTool(name: 'swc_call', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function call(int $device_id, #[Schema(pattern: '^[a-z0-9_]+$')] string $module, array $arguments = []): CallToolResult
    {
        return $this->out($this->api->post(WildcoreClient::path('switcher-core', 'device', $module, $device_id), $arguments));
    }
}
