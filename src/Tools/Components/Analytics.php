<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Network-wide reports (analytics component).
 */
#[Requires(permissions: ['analytics'])]
final class Analytics extends AbstractTools
{
    public const GROUP = 'analytics';
    public const COMPONENT = 'analytics';

    /**
     * Network reports:
     * - bad_signals - number of ONUs with bad optical signal;
     * - signal_strength - ONUs with their signal levels;
     * - increasing_errors - interfaces where error counters keep growing (cable/SFP problems);
     * - device_errors - growing errors on interfaces of one device (needs device_id);
     * - duplicated_macs - MAC addresses seen on several ports;
     * - duplicated_onts - ONU identifiers registered more than once;
     * - ont_statuses_history - ONU status changes (flapping subscribers).
     *
     * @param string   $report    which report
     * @param int|null $device_id device ID for device_errors
     * @param int      $limit     maximum number of rows for list reports
     * @param int      $offset    number of rows to skip
     */
    #[McpTool(name: 'analytics_report', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function report(
        #[Schema(enum: ['bad_signals', 'signal_strength', 'increasing_errors', 'device_errors', 'duplicated_macs', 'duplicated_onts', 'ont_statuses_history'])] string $report,
        ?int $device_id = null,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $data = match ($report) {
            'bad_signals' => $this->api->get('component/analytics/widgets/bad-signals'),
            'signal_strength' => $this->api->get('component/analytics/table/signal-strength'),
            'increasing_errors' => $this->api->get('component/analytics/table/increasing-errors'),
            'device_errors' => $this->api->get(WildcoreClient::path('component', 'analytics', 'errors', 'increasing-data', 'by-device', $device_id ?? throw new ToolCallException('device_errors needs device_id'))),
            'duplicated_macs' => $this->api->put('component/analytics/table/duplicated-mac-addresses'),
            'duplicated_onts' => $this->api->get('component/analytics/table/duplicated-onts'),
            'ont_statuses_history' => $this->api->get('component/analytics/table/ont-statuses-history'),
        };
        if (\is_array($data) && isset($data['data']) && \array_key_exists('meta', $data)) {
            $data = $data['data'];
        }

        return $this->out(\is_array($data) && array_is_list($data) ? $this->paginate($data, $limit, $offset) : $data);
    }
}
