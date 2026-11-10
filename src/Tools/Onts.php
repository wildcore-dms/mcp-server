<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\WildcoreClient;

#[Requires(permissions: ['device_show'])]
final class Onts extends AbstractTools
{
    public const GROUP = 'onts';

    /**
     * List ONUs known to WildCore with last optical metrics (rx, olt_rx, tx, temperature, distance).
     * Optional filter compares one field of each record, e.g. field "optical.rx", expression "<", value "-27"
     * finds ONUs with weak signal; field "any" with "~" searches the whole record by regex.
     * WildCore compares loosely: ONUs without metrics (null) also match "<" / "<=" - check values in result.
     *
     * @param list<int>   $device_ids OLT device IDs, empty - all OLTs
     * @param string|null $field      record field in dot notation: optical.rx, interface.status, interface.name, ident, interface.device.ip ...
     * @param string|null $expression comparison applied to field
     * @param string|null $value      value to compare with
     * @param int         $page       page number, starting from 1
     * @param int         $limit      ONUs per page
     */
    #[McpTool(name: 'onts_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(
        array $device_ids = [],
        ?string $field = null,
        #[Schema(enum: ['=', '!=', '>', '<', '>=', '<=', '~'])] ?string $expression = null,
        ?string $value = null,
        #[Schema(minimum: 1)] int $page = 1,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
    ): CallToolResult {
        $filter = [];
        if ($device_ids) {
            $filter['devices'] = array_map(static fn ($id) => ['id' => (int) $id], $device_ids);
        }
        if (null !== $field) {
            $filter += ['field' => $field, 'expression' => $expression ?? '=', 'value' => $value ?? ''];
        }

        return $this->out($this->api->put('device-interface/ont-list', [
            'filter' => (object) $filter,
            'page' => $page,
            'limit' => $limit,
        ]));
    }

    /**
     * Get stored ONU optical signal levels with min/max envelope collected by poller.
     * Set device_id or interface_id, otherwise signals of all ONUs are returned.
     *
     * @param int|null $device_id    OLT device ID
     * @param int|null $interface_id ONU interface ID
     * @param int      $limit        maximum number of records to return
     * @param int      $offset       number of records to skip
     */
    #[McpTool(name: 'ont_signals', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function signals(
        ?int $device_id = null,
        ?int $interface_id = null,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get('ont-signal', compact('device_id', 'interface_id'));

        return $this->out(null !== $interface_id ? $list : $this->paginate($list, $limit, $offset));
    }

    /**
     * Get ONU list directly from OLT (status, signal, distance, serial) through switcher-core.
     *
     * @param int    $device_id OLT device ID
     * @param string $source    cache - cached data refreshed if stale; store - only cache; device - live request
     * @param int    $limit     maximum number of ONUs to return
     * @param int    $offset    number of ONUs to skip
     */
    #[Requires(permissions: ['olts_info'], components: ['olts'])]
    #[McpTool(name: 'olt_onts', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function oltOnts(
        int $device_id,
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get(WildcoreClient::path('component', 'olts', 'interfaces', 'onts', $device_id), ['from' => $this->source($source)]);

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * Get full details of one ONU from OLT: status, optical levels, UNI ports, MAC addresses, configuration.
     * Use source "history" to get stored history of the ONU instead.
     *
     * @param int    $device_id OLT device ID
     * @param string $bind_key  ONU interface bind key (interface.bind_key from onts_list or device_interfaces), not the interface name
     * @param string $source    cache, store, device (live) or history
     */
    #[Requires(permissions: ['olts_info'], components: ['olts'])]
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
    #[Requires(permissions: ['olts_info'], components: ['olts'])]
    #[McpTool(name: 'olt_pon_ports', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function ponPorts(int $device_id, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'olts', 'interfaces', 'pon-ports', $device_id), ['from' => $this->source($source)]));
    }

    /**
     * Reset stored min/max optical signal envelope of one ONU (WildCore data only, the ONU is not touched).
     *
     * @param int $interface_id ONU interface ID
     */
    #[Requires(permissions: ['device_iface_management'])]
    #[McpTool(name: 'ont_signal_reset', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    public function resetSignal(int $interface_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('ont-signal', 'reset', 'interface', $interface_id)));
    }
}
