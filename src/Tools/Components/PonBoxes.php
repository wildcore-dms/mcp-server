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
 * PON boxes (splitter boxes) with their ONUs and fill level (pon_boxes component).
 */
#[Requires(permissions: ['pon_boxes_view'])]
final class PonBoxes extends AbstractTools
{
    public const GROUP = 'pon_boxes';
    public const COMPONENT = 'pon_boxes';

    /**
     * List PON boxes, optionally of one OLT or in one status.
     *
     * @param int|null    $device_id   only boxes of this OLT
     * @param string|null $status      box status, e.g. OK, PROBLEM
     * @param bool        $only_filled only boxes with no or few free ports
     * @param int         $limit       maximum number of boxes
     * @param int         $offset      number of boxes to skip
     */
    #[McpTool(name: 'pon_boxes_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(
        ?int $device_id = null,
        ?string $status = null,
        bool $only_filled = false,
        #[Schema(minimum: 1, maximum: 1000)] int $limit = 100,
        #[Schema(minimum: 0)] int $offset = 0,
    ): CallToolResult {
        $list = $this->api->get('component/pon_boxes', ['device_id' => $device_id, 'status' => $status, 'only_filled' => $only_filled ? 'yes' : null]);

        return $this->out($this->paginate($list, $limit, $offset));
    }

    /**
     * Get a PON box with its ONUs, by ID or by its number.
     *
     * @param int|null    $box_id box ID
     * @param string|null $number box number (label on the box)
     */
    #[McpTool(name: 'pon_box_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(?int $box_id = null, ?string $number = null): CallToolResult
    {
        return $this->out(null !== $box_id
            ? $this->api->get(WildcoreClient::path('component', 'pon_boxes', $box_id))
            : $this->api->get('component/pon_boxes/by', ['number' => $number ?? throw new \Mcp\Exception\ToolCallException('Pass box_id or number')]));
    }

    /**
     * PON boxes in PROBLEM state grouped by OLT - where many ONUs of one box are down at once.
     */
    #[McpTool(name: 'pon_boxes_problems', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function problems(): CallToolResult
    {
        return $this->out($this->api->get('component/pon_boxes/with-problems'));
    }
}
