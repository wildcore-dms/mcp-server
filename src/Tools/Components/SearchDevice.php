<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;

/**
 * Find where a MAC or IP address lives in the network (search_device component).
 * Without "devices" every device is queried live - slow on a big network, so narrow it when possible.
 */
final class SearchDevice extends AbstractTools
{
    public const GROUP = 'search_device';
    public const COMPONENT = 'search_device';

    /**
     * Find the switch port (or ONU) where a MAC address is seen: FDB tables of devices and stored history.
     *
     * @param string       $mac     MAC address in any common format
     * @param list<string> $devices device IDs or IPs to search, empty - all devices
     */
    #[Requires(permissions: ['sd_search_mac'])]
    #[McpTool(name: 'find_mac', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function mac(string $mac, array $devices = []): CallToolResult
    {
        return $this->out($this->api->post('component/search_device/search-mac', array_filter(compact('mac', 'devices'))));
    }

    /**
     * Find an IP address: ARP on routers gives its MAC and VLAN; with with_port=true the MAC is then looked up in
     * FDB tables to find the access port.
     *
     * @param string       $ip        IP address
     * @param bool         $with_port also find the switch port by the resolved MAC
     * @param list<string> $routers   routers (IDs or IPs) to query for ARP, empty - all devices
     * @param list<string> $devices   devices (IDs or IPs) to query for FDB when with_port, empty - all devices
     */
    #[Requires(permissions: ['sd_search_ip', 'sd_search_ip_with_port'])]
    #[McpTool(name: 'find_ip', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function ip(string $ip, bool $with_port = true, array $routers = [], array $devices = []): CallToolResult
    {
        return $this->out($with_port
            ? $this->api->post('component/search_device/search-ip-with-fdb', array_filter(compact('ip', 'routers', 'devices')))
            : $this->api->post('component/search_device/search-ip', array_filter(['ip' => $ip, 'devices' => $routers])));
    }
}
