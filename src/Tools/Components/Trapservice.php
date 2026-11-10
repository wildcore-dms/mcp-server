<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;

/**
 * SNMP traps received by WildCore (trapservice component): link up/down, ONU events, logins ...
 */
#[Requires(permissions: ['diag_interface'])]
final class Trapservice extends AbstractTools
{
    public const GROUP = 'trapservice';
    public const COMPONENT = 'trapservice';

    /**
     * Search received SNMP traps.
     *
     * @param int|null     $device_id    only traps from this device
     * @param int|null     $interface_id only traps about this interface
     * @param list<string> $names        only these trap names, see trap_names
     * @param string|null  $object       SNMP trap object OID or name
     * @param string|null  $query        free text over description, name and object
     * @param string|null  $start        period start, e.g. "2026-11-10 00:00:00"; default - stop minus hours
     * @param string|null  $stop         period end; default - now
     * @param int          $hours        period length when start is not set
     * @param int          $page         page number, starting from 1
     * @param int          $limit        traps per page
     */
    #[McpTool(name: 'traps_history', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function history(
        ?int $device_id = null,
        ?int $interface_id = null,
        array $names = [],
        ?string $object = null,
        ?string $query = null,
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        #[Schema(minimum: 1)] int $page = 1,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
    ): CallToolResult {
        $period = $this->period($start, $stop, $hours);
        $body = array_filter(compact('device_id', 'names', 'object', 'query', 'page', 'limit')) + [
            'start' => $period['start'],
            'end' => $period['stop'],
        ];
        if (null !== $interface_id) {
            $body['interface'] = ['id' => $interface_id];
        }

        return $this->out($this->api->post('component/trapservice/history', $body));
    }

    /**
     * List trap names recorded in history (for the names filter of traps_history).
     */
    #[McpTool(name: 'trap_names', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function names(): CallToolResult
    {
        return $this->out($this->api->get('component/trapservice/history/params/object-names'));
    }

    /**
     * Trap statistics by device and by trap object - which devices send most traps.
     */
    #[McpTool(name: 'traps_stat', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function stat(): CallToolResult
    {
        return $this->out([
            'by_device' => $this->api->get('component/trapservice/history/stat/by-device'),
            'by_object' => $this->api->get('component/trapservice/history/stat/by-objects'),
        ]);
    }
}
