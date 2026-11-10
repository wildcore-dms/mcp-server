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
 * OLT data through switcher-core (olts component).
 */
#[Requires(permissions: ['olts_info'])]
final class Olts extends AbstractTools
{
    public const GROUP = 'olts';
    public const COMPONENT = 'olts';

    /**
     * Get ONU list directly from OLT (status, signal, distance, serial).
     *
     * @param int    $device_id OLT device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     * @param int    $limit     maximum number of ONUs to return
     * @param int    $offset    number of ONUs to skip
     */
    #[McpTool(name: 'olt_onts', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function onts(
        int $device_id,
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        return $this->out($this->paginate($this->get(['interfaces', 'onts', $device_id], $source), $limit, $offset));
    }

    /**
     * Get full details of one ONU from OLT: status, optical levels, UNI ports, MAC addresses, configuration.
     * Use source "history" to get stored history of the ONU instead.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key (interface.bind_key from onts_list or device_interfaces), not the interface name
     * @param string $source    cache, store, device (live) or history
     */
    #[McpTool(name: 'ont_info', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function info(
        int $device_id,
        #[Schema(minLength: 1)] string $bind_key,
        #[Schema(enum: ['cache', 'store', 'device', 'history'])] string $source = 'cache',
    ): CallToolResult {
        $source = 'history' === $source ? $source : $this->source($source);

        return $this->out($this->api->get(WildcoreClient::path('component', 'olts', 'interfaces', 'ont', $device_id, $bind_key), ['from' => $source]));
    }

    /**
     * List PON ports of OLT with ONU counters and optical data.
     *
     * @param int    $device_id OLT device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_pon_ports', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function ponPorts(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['interfaces', 'pon-ports', $device_id], $source));
    }

    /**
     * Get status of physical (uplink/ethernet) ports of OLT.
     *
     * @param int         $device_id OLT device ID
     * @param string|null $interface only this interface
     * @param string      $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_physical_ports', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function physicalPorts(int $device_id, ?string $interface = null, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['interfaces', 'physical', $device_id], $source, ['interface' => $interface]));
    }

    /**
     * Get DHCP snooping bindings on OLT (which MAC/IP/VLAN is behind which ONU). Filter to keep the result small.
     *
     * @param int         $device_id   OLT device ID
     * @param string|null $interface   ONU or PON interface
     * @param string|null $mac_address MAC address
     * @param int|null    $vlan_id     VLAN ID
     * @param string|null $ip          IP address
     * @param string      $source      cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_snooping', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function snooping(
        int $device_id,
        ?string $interface = null,
        ?string $mac_address = null,
        ?int $vlan_id = null,
        ?string $ip = null,
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->get(['snooping', $device_id], $source, compact('interface', 'mac_address', 'vlan_id', 'ip')));
    }

    /**
     * Get OLT line cards and their status.
     *
     * @param int    $device_id OLT device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_cards', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function cards(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['cards', 'status', $device_id], $source));
    }

    /**
     * Get OLT system resources: CPU, memory, temperature.
     *
     * @param int    $device_id OLT device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_resources', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function resources(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['system', 'resources', $device_id], $source));
    }

    /**
     * Get ONU black-list (blocked serials/MACs that can not register) on one OLT or on all OLTs - explains why a new
     * ONU does not appear or register. Supported on C-Data OLTs; read from OLTs every 10 minutes.
     *
     * @param int|null $device_id OLT device ID, empty - all OLTs
     * @param string   $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'olt_onts_blacklist', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function blacklist(?int $device_id = null, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(null === $device_id ? ['blacklisted-onts'] : ['blacklisted-onts', $device_id], $source));
    }

    /**
     * @param list<string|int>     $segments path inside /component/olts
     * @param array<string, mixed> $query
     */
    private function get(array $segments, string $source, array $query = []): mixed
    {
        return $this->api->get(WildcoreClient::path('component', 'olts', ...$segments), ['from' => $this->source($source)] + $query);
    }
}
