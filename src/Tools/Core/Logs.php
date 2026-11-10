<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Core;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;

/**
 * WildCore logs: system actions (who did what), switcher-core calls with their errors,
 * poller runs and scheduled jobs. Periods are in WildCore server time.
 */
final class Logs extends AbstractTools
{
    public const GROUP = 'logs';

    /**
     * Get system action log: user actions, module calls, macros, registrations, configuration changes.
     * Set status "FAILED" to see only failed actions with their errors.
     *
     * @param string|null  $start   period start, e.g. "2026-11-10 00:00:00"; default - stop minus hours
     * @param string|null  $stop    period end; default - now
     * @param int          $hours   period length when start is not set
     * @param list<string> $actions only these action keys, see logs_action_names
     * @param list<int>    $users   only actions of these user IDs
     * @param string|null  $status  SUCCESS or FAILED
     * @param string|null  $query   text to search in messages
     * @param bool         $verbose include full meta of every record (commands, outputs, variables)
     * @param int          $limit   maximum number of records
     * @param int          $offset  number of records to skip
     */
    #[Requires(permissions: ['system_logs_actions'])]
    #[McpTool(name: 'logs_actions', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function actions(
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        array $actions = [],
        array $users = [],
        #[Schema(enum: ['SUCCESS', 'FAILED'])] ?string $status = null,
        ?string $query = null,
        bool $verbose = false,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->post('logs/actions', $this->period($start, $stop, $hours) + array_filter(compact('actions', 'users', 'status', 'query')));

        return $this->out($this->slice($list, $limit, $offset, fn (array $r) => [
            'id' => $r['id'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'action' => $r['action'] ?? null,
            'status' => $r['status'] ?? null,
            'message' => $r['message'] ?? null,
            'user' => self::user($r['user'] ?? null),
            'device' => self::device($r['device'] ?? null),
            'error' => isset($r['meta']['error']) ? self::error($r['meta']['error']) : null,
        ] + ($verbose ? ['meta' => $r['meta'] ?? null] : [])));
    }

    /**
     * List action keys that can be used in logs_actions (swcore:*, macros:*, user:* ...).
     */
    #[Requires(permissions: ['system_logs_actions'])]
    #[McpTool(name: 'logs_action_names', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function actionNames(): CallToolResult
    {
        return $this->out($this->api->get('logs/actions-list'));
    }

    /**
     * Get switcher-core call log: which module was called on which device, by whom, and why it failed
     * (status "FAILED" - calling errors, timeouts, device not responding).
     * WildCore returns at most 500 records - narrow the period or filter by device when total is 500.
     *
     * @param string|null  $start     period start; default - stop minus hours
     * @param string|null  $stop      period end; default - now
     * @param int          $hours     period length when start is not set
     * @param int|null     $device_id only calls to this device
     * @param list<string> $modules   only these modules, see logs_switcher_core_modules
     * @param list<int>    $users     only calls of these user IDs
     * @param string|null  $status    SUCCESS or FAILED
     * @param string|null  $query     text to search
     * @param bool         $verbose   include arguments, device output (telnet/ssh) and returned data
     * @param int          $limit     maximum number of records
     * @param int          $offset    number of records to skip
     */
    #[Requires(permissions: ['log_switcher_core_actions'])]
    #[McpTool(name: 'logs_switcher_core', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function switcherCore(
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        ?int $device_id = null,
        array $modules = [],
        array $users = [],
        #[Schema(enum: ['SUCCESS', 'FAILED'])] ?string $status = null,
        ?string $query = null,
        bool $verbose = false,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->post('logs/switcher-core/actions', $this->period($start, $stop, $hours) + array_filter(compact('device_id', 'modules', 'users', 'status', 'query')));

        return $this->out($this->slice($list, $limit, $offset, fn (array $r) => [
            'id' => $r['id'] ?? null,
            'time' => $r['time'] ?? null,
            'module' => $r['module'] ?? null,
            'status' => $r['status'] ?? null,
            'device' => self::device($r['device'] ?? null),
            'user' => self::user($r['user'] ?? null),
            'error' => isset($r['meta']['error']) ? self::error($r['meta']['error']) : null,
        ] + ($verbose ? [
            'arguments' => $r['arguments'] ?? null,
            'device_output' => isset($r['meta']['telnet_output']) ? mb_substr((string) $r['meta']['telnet_output'], -4000) : null,
            'data' => $r['data'] ?? null,
        ] : [])));
    }

    /**
     * List switcher-core modules present in the call log (for the modules filter of logs_switcher_core).
     */
    #[Requires(permissions: ['log_switcher_core_actions'])]
    #[McpTool(name: 'logs_switcher_core_modules', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function switcherCoreModules(): CallToolResult
    {
        return $this->out($this->api->get('logs/switcher-core/supported-modules'));
    }

    /**
     * Devices with switcher-core calling errors and "not responding" counters (top 30), as on the dashboard.
     * Start here to find devices that WildCore can not poll.
     */
    #[Requires(permissions: ['log_switcher_core_actions'])]
    #[McpTool(name: 'calling_errors', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function callingErrors(): CallToolResult
    {
        return $this->out($this->api->get('dashboard/widget/error-calling-by-device'));
    }

    /**
     * Get poller run log: which poller ran on which device, when, and the error if it failed.
     *
     * @param string|null  $start      period start; default - stop minus hours
     * @param string|null  $stop       period end; default - now
     * @param int          $hours      period length when start is not set
     * @param list<int>    $device_ids only these devices
     * @param list<string> $pollers    only these pollers, see logs_poller_names
     * @param string|null  $status     SUCCESS or FAILED
     * @param string|null  $query      text to search
     * @param int          $limit      maximum number of records
     * @param int          $offset     number of records to skip
     */
    #[Requires(permissions: ['log_poller'])]
    #[McpTool(name: 'logs_pollers', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function pollers(
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        array $device_ids = [],
        array $pollers = [],
        #[Schema(enum: ['SUCCESS', 'FAILED'])] ?string $status = null,
        ?string $query = null,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $devices = $device_ids;
        $list = $this->api->post('logs/poller/pollers', $this->period($start, $stop, $hours) + array_filter(compact('devices', 'pollers', 'status', 'query')));

        return $this->out($this->slice($list, $limit, $offset, fn (array $r) => [
            'id' => $r['id'] ?? null,
            'poller' => $r['poller'] ?? null,
            'status' => $r['status'] ?? null,
            'start_at' => $r['start_at'] ?? null,
            'stop_at' => $r['stop_at'] ?? null,
            'device' => self::device($r['device'] ?? null),
            'error' => isset($r['error']) ? self::error($r['error']) : null,
        ]));
    }

    /**
     * List poller names (for the pollers filter of logs_pollers).
     */
    #[Requires(permissions: ['log_poller'])]
    #[McpTool(name: 'logs_poller_names', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function pollerNames(): CallToolResult
    {
        return $this->out($this->api->get('logs/poller/existed-pollers'));
    }

    /**
     * Get scheduled job (cron) runs with output and errors. Use only_failed to find broken jobs.
     *
     * @param string|null  $start         period start; default - stop minus hours
     * @param string|null  $stop          period end; default - now
     * @param int          $hours         period length when start is not set
     * @param list<string> $schedule_keys only these jobs, see logs_schedule_keys
     * @param bool         $only_failed   only failed runs
     * @param bool         $verbose       include full job output
     * @param int          $limit         maximum number of records
     * @param int          $offset        number of records to skip
     */
    #[Requires(permissions: ['schedule_reports'])]
    #[McpTool(name: 'logs_schedule', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function schedule(
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        array $schedule_keys = [],
        bool $only_failed = false,
        bool $verbose = false,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->post('logs/schedule/reports', $this->period($start, $stop, $hours) + array_filter(compact('schedule_keys', 'only_failed')));

        return $this->out($this->slice($list, $limit, $offset, fn (array $r) => [
            'id' => $r['id'] ?? null,
            'job' => $r['schedule']['key'] ?? null,
            'command' => $r['schedule']['command'] ?? null,
            'start_at' => $r['start_at'] ?? null,
            'stop_at' => $r['stop_at'] ?? null,
            'successful' => $r['is_successful'] ?? null,
            'error' => self::error($r['error'] ?? null) ?: null,
            'output' => $verbose ? ($r['output'] ?? null) : (null !== ($r['output'] ?? null) && '' !== $r['output'] ? mb_substr((string) $r['output'], -500) : null),
        ]));
    }

    /**
     * List scheduled jobs (keys for logs_schedule).
     */
    #[Requires(permissions: ['schedule_reports'])]
    #[McpTool(name: 'logs_schedule_keys', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function scheduleKeys(): CallToolResult
    {
        return $this->out($this->api->get('logs/schedule/keys'));
    }

    /**
     * Latest system actions as shown on the dashboard - quick look at what happened recently.
     */
    #[Requires(permissions: ['system_info'])]
    #[McpTool(name: 'latest_system_actions', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function latest(): CallToolResult
    {
        return $this->out(array_map(static fn (array $r) => [
            'created_at' => $r['created_at'] ?? null,
            'action' => $r['action'] ?? null,
            'status' => $r['status'] ?? null,
            'message' => $r['message'] ?? null,
            'user' => self::user($r['user'] ?? null),
        ], array_values(array_filter((array) $this->api->get('dashboard/latest-system-actions'), 'is_array'))));
    }

    /**
     * Records sliced and mapped to a compact form, in WildCore order.
     *
     * @return array{total: int, offset: int, limit: int, items: list<mixed>}
     */
    private function slice(mixed $list, int $limit, int $offset, \Closure $map): array
    {
        $page = $this->paginate($list, $limit, $offset);
        $page['items'] = array_map(static fn ($r) => \is_array($r) ? array_filter($map($r), static fn ($v) => null !== $v) : $r, $page['items']);

        return $page;
    }
}
