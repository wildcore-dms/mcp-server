<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use WildcoreMcp\Config;
use WildcoreMcp\WildcoreClient;

/**
 * Base for a group of tools. GROUP is the tag of every tool in the class,
 * so a whole group can be switched off with DISABLED_TAGS.
 */
abstract class AbstractTools
{
    public const GROUP = '';

    /** Data source for switcher-core based calls: cache, store (cache only) or device (live). */
    protected const SOURCES = ['cache', 'store', 'device'];

    public function __construct(
        protected readonly WildcoreClient $api,
        protected readonly Config $config,
    ) {
    }

    /**
     * Slices a full list returned by WildCore, so big OLT/interface lists do not flood the context.
     *
     * @return array{total: int, offset: int, limit: int, items: list<mixed>}
     */
    protected function paginate(mixed $list, int $limit, int $offset): array
    {
        if (\is_array($list) && isset($list['data']) && \array_key_exists('meta', $list)) {
            $list = $list['data'];
        }
        $list = \is_array($list) ? array_values($list) : [];

        return [
            'total' => \count($list),
            'offset' => $offset,
            'limit' => $limit,
            'items' => \array_slice($list, $offset, $limit),
        ];
    }

    /**
     * Returns compact JSON as text (SDK default is pretty-printed text plus structuredContent copy,
     * that doubles tokens). Refuses results that would not fit into the model context instead of cutting JSON.
     */
    protected function out(mixed $data): CallToolResult
    {
        $json = (string) json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (\strlen($json) > $this->config->maxResultBytes) {
            throw new ToolCallException(\sprintf('Result is %d bytes, more than the %d byte limit - narrow the request with filters, interface, limit or offset', \strlen($json), $this->config->maxResultBytes));
        }

        return new CallToolResult([new TextContent($json)]);
    }

    protected function source(string $source): string
    {
        if (!\in_array($source, self::SOURCES, true)) {
            throw new ToolCallException("source must be one of: ".implode(', ', self::SOURCES));
        }

        return $source;
    }
}
