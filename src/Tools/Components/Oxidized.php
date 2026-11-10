<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;
use WildcoreMcp\WildcoreClient;

/**
 * Device configuration backups (oxidized component).
 */
#[Requires(permissions: ['external_apps_oxidized'])]
final class Oxidized extends AbstractTools
{
    public const GROUP = 'oxidized';
    public const COMPONENT = 'oxidized';

    /**
     * Status of the configuration backup of a device: last backup time and result.
     *
     * @param int $device_id device ID
     */
    #[McpTool(name: 'device_config_status', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function status(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'oxidized', 'data', 'status', $device_id)));
    }

    /**
     * Latest backed up configuration of a device. Contains credentials (passwords, SNMP communities, keys) -
     * listed only with ENABLE_SECRETS=true; do not repeat secrets to the user unless asked.
     *
     * @param int $device_id device ID
     */
    #[Requires(permissions: ['external_apps_oxidized'], secret: true)]
    #[McpTool(name: 'device_config', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function config(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'oxidized', 'data', 'config', $device_id)));
    }
}
