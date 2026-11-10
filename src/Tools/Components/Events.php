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
 * Events (alerts) raised by WildCore and Alertmanager rules: device down, high utilization, bad signal ...
 */
#[Requires(permissions: ['events_show'])]
final class Events extends AbstractTools
{
    public const GROUP = 'events';
    public const COMPONENT = 'events';

    /**
     * List events with filters. Use not_resolved=true for current problems.
     *
     * @param int|null    $device_id    only events of this device
     * @param string|null $severity     severity, e.g. CRITICAL, WARNING, INFO
     * @param string|null $name         event name, see event_names (e.g. pinger_host_down)
     * @param bool        $not_resolved only events that are not resolved yet
     * @param int         $page         page number, starting from 1
     * @param int         $limit        events per page
     */
    #[McpTool(name: 'events_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(
        ?int $device_id = null,
        ?string $severity = null,
        ?string $name = null,
        bool $not_resolved = false,
        #[Schema(minimum: 1)] int $page = 1,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
    ): CallToolResult {
        $result = $this->api->get('component/events', array_filter(compact('device_id', 'severity', 'name', 'not_resolved', 'page', 'limit')));
        $events = \is_array($result) && isset($result['data']) ? $result['data'] : $result;

        return $this->out([
            'meta' => $result['meta'] ?? null,
            'events' => array_map(static fn ($e) => \is_array($e) ? array_filter([
                'id' => $e['id'] ?? null,
                'name' => $e['name'] ?? null,
                'annotation' => $e['annotation'] ?? null,
                'severity' => $e['severity'] ?? null,
                'description' => $e['description'] ?? null,
                'device' => self::device($e['device'] ?? null),
                'labels' => $e['labels'] ?? null,
                'created_at' => $e['created_at'] ?? null,
                'updated_at' => $e['updated_at'] ?? null,
                'resolved_at' => $e['resolved_at'] ?? null,
                'resolved_by' => self::user($e['resolved_by'] ?? null),
            ], static fn ($v) => null !== $v) : $e, \is_array($events) ? array_values($events) : []),
        ]);
    }

    /**
     * Event statistics: counts by severity and by event name - a quick overview of the network state.
     */
    #[McpTool(name: 'events_stat', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function stat(): CallToolResult
    {
        return $this->out([
            'by_severity' => $this->api->get('component/events/severity-stat'),
            'by_name' => $this->api->get('component/events/count-by-name'),
        ]);
    }

    /**
     * List event names known to WildCore (for the name filter of events_list).
     */
    #[McpTool(name: 'event_names', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function names(): CallToolResult
    {
        return $this->out($this->api->get('component/events/params/names'));
    }

    /**
     * Get Alertmanager rules that raise events (expressions, thresholds, severities).
     */
    #[Requires(permissions: ['events_configuration'])]
    #[McpTool(name: 'event_rules', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function rules(): CallToolResult
    {
        return $this->out($this->api->get('component/events/alertmanager'));
    }

    /**
     * Mark one event as resolved (WildCore data only).
     *
     * @param int $event_id event ID
     */
    #[Requires(permissions: ['events_resolve'])]
    #[McpTool(name: 'event_resolve', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))]
    public function resolve(int $event_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('component', 'events', $event_id, 'resolve')));
    }
}
