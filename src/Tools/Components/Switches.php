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
 * Switch data through switcher-core (switches component).
 */
#[Requires(permissions: ['switches_info'])]
final class Switches extends AbstractTools
{
    public const GROUP = 'switches';
    public const COMPONENT = 'switches';

    /**
     * Switch ports: link and admin state, speed, description, errors, VLANs, FDB, optical data.
     * Set interface to get one port - full output for all ports can be large.
     *
     * @param int         $device_id switch device ID
     * @param string|null $interface interface name or bind key
     * @param string      $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'switch_interfaces', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function interfaces(int $device_id, ?string $interface = null, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['interfaces', $device_id], $source, ['interface' => $interface]));
    }

    /**
     * VLANs configured on a switch.
     *
     * @param int    $device_id switch device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'switch_vlans', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function vlans(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['vlans', $device_id], $source));
    }

    /**
     * DHCP snooping bindings on a switch (MAC/IP/VLAN per port). Filter to keep the result small.
     *
     * @param int         $device_id   switch device ID
     * @param string|null $interface   interface name or bind key
     * @param string|null $mac_address MAC address
     * @param int|null    $vlan_id     VLAN ID
     * @param string|null $ip          IP address
     * @param string      $source      cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'switch_snooping', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
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
     * Switch system resources: CPU, memory, temperature, fans, power.
     *
     * @param int    $device_id switch device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'switch_resources', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function resources(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['resources', $device_id], $source));
    }

    /**
     * SFP diagnostics (DDM): optical levels, temperature, voltage of transceivers.
     *
     * @param int         $device_id switch device ID
     * @param string|null $interface interface name or bind key
     * @param string      $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'switch_sfp_diag', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function sfpDiag(int $device_id, ?string $interface = null, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->get(['interfaces', $device_id, 'sfp_diag'], $source, ['interface' => $interface]));
    }

    /**
     * Run cable diagnostics (TDR) on a copper port: pair status and fault distance.
     * On many models the test drops the link for a few seconds - confirm with the user first.
     *
     * @param int    $device_id switch device ID
     * @param string $interface interface name or bind key
     */
    #[McpTool(name: 'switch_cable_diag', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: true))]
    public function cableDiag(int $device_id, #[Schema(minLength: 1)] string $interface): CallToolResult
    {
        return $this->out($this->get(['interfaces', $device_id, 'cable_diag'], 'device', ['interface' => $interface]));
    }

    /**
     * @param list<string|int>     $segments path inside /component/switches
     * @param array<string, mixed> $query
     */
    private function get(array $segments, string $source, array $query = []): mixed
    {
        return $this->api->get(WildcoreClient::path('component', 'switches', ...$segments), ['from' => $this->source($source)] + $query);
    }
}
