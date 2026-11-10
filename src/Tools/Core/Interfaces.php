<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Core;

use WildcoreMcp\Tools\AbstractTools;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\WildcoreClient;

#[Requires(permissions: ['device_show'])]
final class Interfaces extends AbstractTools
{
    public const GROUP = 'interfaces';

    /**
     * List interfaces of all devices (ports, PON ports, ONUs) stored in WildCore, with optional filters.
     *
     * @param int|null    $device_id only interfaces of this device
     * @param string|null $types     comma-separated interface types, see interface_types
     * @param string|null $bind_key  interface bind key (switcher-core interface identifier)
     * @param string|null $agreement subscriber agreement bound to interface
     * @param string|null $ip        device IP address
     * @param int         $limit     maximum number of interfaces to return
     * @param int         $offset    number of interfaces to skip
     */
    #[McpTool(name: 'interfaces_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(
        ?int $device_id = null,
        ?string $types = null,
        ?string $bind_key = null,
        ?string $agreement = null,
        ?string $ip = null,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get('device-interface', compact('device_id', 'types', 'bind_key', 'agreement', 'ip'));

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * Search interfaces by combined parameters: device, interface name or bind key, ONU identifier, MAC address.
     *
     * @param int|null    $device_id          device ID
     * @param string|null $device_ip          device IP address
     * @param string|null $interface_name     interface name, e.g. "eth0/0/1" or "gpon0/1:5"
     * @param string|null $interface_bind_key interface bind key
     * @param string|null $ont_ident          ONU serial number or MAC address
     * @param string|null $mac_address        MAC address seen on interface
     * @param bool        $only_active_mac    with mac_address - search only in current FDB, not in history
     * @param int         $limit              maximum number of interfaces to return
     * @param int         $offset             number of interfaces to skip
     */
    #[McpTool(name: 'interfaces_search', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function search(
        ?int $device_id = null,
        ?string $device_ip = null,
        ?string $interface_name = null,
        ?string $interface_bind_key = null,
        ?string $ont_ident = null,
        ?string $mac_address = null,
        bool $only_active_mac = false,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get('device-interface/search', compact(
            'device_id', 'device_ip', 'interface_name', 'interface_bind_key', 'ont_ident', 'mac_address', 'only_active_mac',
        ));

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * List all interfaces of one device.
     *
     * @param int $device_id device ID
     * @param int $limit     maximum number of interfaces to return
     * @param int $offset    number of interfaces to skip
     */
    #[McpTool(name: 'device_interfaces', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function byDevice(
        int $device_id,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 200,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get(WildcoreClient::path('device-interface', 'by-device', $device_id));

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * Get interface by ID.
     *
     * @param int $interface_id interface ID
     */
    #[McpTool(name: 'interface_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(int $interface_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('device-interface', $interface_id)));
    }

    /**
     * Get interface status change history (up/down, description changes).
     *
     * @param int $interface_id interface ID
     * @param int $limit        maximum number of records to return
     * @param int $offset       number of records to skip
     */
    #[McpTool(name: 'interface_history', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function history(
        int $interface_id,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get(WildcoreClient::path('device-interface', 'history', $interface_id));

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * List supported interface types (for the types filter of interfaces_list).
     */
    #[McpTool(name: 'interface_types', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function types(): CallToolResult
    {
        return $this->out($this->api->get('device-interface/types'));
    }
}
