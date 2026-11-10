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
 * Subscriber diagnostics (diagnostic component).
 */
final class Diagnostic extends AbstractTools
{
    public const GROUP = 'diagnostic';
    public const COMPONENT = 'diagnostic';

    /**
     * Full diagnostic of a subscriber interface (port or ONU) in one call: link, errors, optical levels, MACs,
     * snooping, history - the same as the diagnostic card in WildCore.
     *
     * @param int         $interface_id interface ID
     * @param string      $source       cache - cached data refreshed if stale; store - only cache; device - live request
     * @param string|null $load_modules comma-separated diagnostic modules to run, empty - defaults
     */
    #[Requires(permissions: ['diag_interface'])]
    #[McpTool(name: 'interface_diagnostic', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function interface(int $interface_id, #[Schema(enum: self::SOURCES)] string $source = 'cache', ?string $load_modules = null): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'diagnostic', 'interface', $interface_id, 'diag'), ['from' => $this->source($source), 'load_modules' => $load_modules]));
    }

    /**
     * ARP-ping an IP from a router to check whether a subscriber device answers in its VLAN.
     *
     * @param string   $router  router device ID or IP
     * @param string   $ip      target IP address
     * @param int|null $vlan_id VLAN ID, detected from ARP when empty
     * @param int      $count   number of pings
     */
    #[Requires(permissions: ['diag_arp_ping'])]
    #[McpTool(name: 'arp_ping', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function arpPing(string $router, string $ip, ?int $vlan_id = null, #[Schema(minimum: 1, maximum: 20)] int $count = 4): CallToolResult
    {
        return $this->out($this->api->post('component/diagnostic/arp-ping', array_filter(compact('router', 'ip', 'vlan_id', 'count'), static fn ($v) => null !== $v)));
    }
}
