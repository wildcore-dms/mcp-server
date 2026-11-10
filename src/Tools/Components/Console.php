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
 * Logs of web console (telnet/ssh) sessions to devices (console component).
 */
#[Requires(permissions: ['external_apps_console_view_logs'])]
final class Console extends AbstractTools
{
    public const GROUP = 'console';
    public const COMPONENT = 'console';

    /**
     * Search web console sessions: who connected to which device, when, and whether the session had errors.
     *
     * @param string|null $start              period start; default - stop minus hours
     * @param string|null $stop               period end; default - now
     * @param int         $hours              period length when start is not set
     * @param list<int>   $device_ids         only sessions to these devices
     * @param list<int>   $user_ids           only sessions of these users
     * @param bool        $only_has_error     only sessions with errors
     * @param bool        $only_open_sessions only sessions that are still open
     * @param string|null $query              text to search
     * @param int         $limit              maximum number of sessions
     * @param int         $offset             number of sessions to skip
     */
    #[McpTool(name: 'console_logs', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function logs(
        ?string $start = null,
        ?string $stop = null,
        #[Schema(minimum: 1, maximum: 720)] int $hours = 24,
        array $device_ids = [],
        array $user_ids = [],
        bool $only_has_error = false,
        bool $only_open_sessions = false,
        ?string $query = null,
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $body = $this->period($start, $stop, $hours) + array_filter([
            'devices' => array_map(static fn ($id) => ['id' => (int) $id], $device_ids),
            'users' => array_map(static fn ($id) => ['id' => (int) $id], $user_ids),
            'only_has_error' => $only_has_error,
            'only_open_sessions' => $only_open_sessions,
            'query' => $query,
        ]);

        return $this->out($this->paginate($this->api->post('component/console/logs', $body), $limit, $offset));
    }

    /**
     * Get one console session log with the commands and device output.
     * May contain credentials typed in the session, so it is listed only with ENABLE_SECRETS=true.
     *
     * @param int $log_id session log ID from console_logs
     */
    #[Requires(permissions: ['external_apps_console_view_logs'], secret: true)]
    #[McpTool(name: 'console_log_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(int $log_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'console', 'logs', $log_id)));
    }
}
