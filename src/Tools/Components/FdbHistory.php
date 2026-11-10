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
 * Stored history of MAC addresses seen on ports (fdb_history component).
 */
#[Requires(permissions: ['fdb_history_by_interface'])]
final class FdbHistory extends AbstractTools
{
    public const GROUP = 'fdb_history';
    public const COMPONENT = 'fdb_history';

    /**
     * MAC address history of a device or one of its ports: which MAC was seen where and when.
     *
     * @param int         $device_id device ID
     * @param string|null $bind_key  only this interface (bind key)
     * @param bool        $active    only MACs present now
     * @param int         $limit     maximum number of records
     * @param int         $offset    number of records to skip
     */
    #[McpTool(name: 'fdb_history', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function history(
        int $device_id,
        ?string $bind_key = null,
        bool $active = false,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $path = null === $bind_key
            ? WildcoreClient::path('component', 'fdb_history', $device_id)
            : WildcoreClient::path('component', 'fdb_history', $device_id, $bind_key);

        return $this->out($this->paginate($this->api->get($path, ['active' => $active ?: null]), $limit, $offset));
    }
}
