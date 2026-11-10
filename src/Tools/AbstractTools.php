<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use WildcoreMcp\Config;
use WildcoreMcp\WildcoreClient;

/**
 * Base for a group of tools. Groups follow WildCore structure: core areas (Tools\Core) and
 * components (Tools\Components, GROUP = component key). GROUP is the tag of every tool in the class,
 * so a whole group can be switched off with DISABLED_TAGS; COMPONENT must be enabled in WildCore
 * for the tools to be listed.
 */
abstract class AbstractTools
{
    public const GROUP = '';
    public const COMPONENT = null;

    /** Data source for switcher-core based calls: cache, store (cache only) or device (live). */
    protected const SOURCES = ['cache', 'store', 'device'];

    /**
     * @param array{components: list<string>, permissions: list<string>, root: bool}|null $capabilities null - unknown
     */
    public function __construct(
        protected readonly WildcoreClient $api,
        protected readonly Config $config,
        protected readonly ?array $capabilities = null,
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

    /**
     * Log period as WildCore expects it ("Y-m-d H:i:s", WildCore server time): explicit start/stop,
     * or the last $hours hours in the timezone of this server (set TZ to the WildCore timezone).
     *
     * @return array{start: string, stop: string}
     */
    protected function period(?string $start, ?string $stop, int $hours): array
    {
        $format = static function (?string $value, string $name): ?string {
            if (null === $value || '' === trim($value)) {
                return null;
            }
            $date = date_create($value) ?: throw new ToolCallException("$name must be a date/time like 2026-11-10 14:00:00");

            return $date->format('Y-m-d H:i:s');
        };
        $stop = $format($stop, 'stop') ?? date('Y-m-d H:i:s');

        return [
            'start' => $format($start, 'start') ?? date('Y-m-d H:i:s', strtotime($stop) - $hours * 3600),
            'stop' => $stop,
        ];
    }

    /**
     * Short device reference instead of the full WildCore device object (access profile, group, params ...).
     *
     * @return array<string, mixed>|null
     */
    protected static function device(mixed $device): ?array
    {
        if (!\is_array($device)) {
            return null;
        }

        return array_filter([
            'id' => $device['id'] ?? null,
            'ip' => $device['ip'] ?? null,
            'name' => $device['name'] ?? null,
            'model' => $device['model']['name'] ?? null,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function user(mixed $user): ?array
    {
        return \is_array($user) ? array_filter(['id' => $user['id'] ?? null, 'login' => $user['login'] ?? null, 'name' => $user['name'] ?? null], static fn ($v) => null !== $v) : null;
    }

    /**
     * Error message with place, without the stack trace.
     */
    protected static function error(mixed $error): mixed
    {
        if (!\is_array($error)) {
            return \is_string($error) ? trim($error) : $error;
        }
        $message = trim((string) ($error['message'] ?? ''));
        $place = $error['breakpoint'] ?? (isset($error['file']) ? $error['file'].':'.($error['line'] ?? '') : null);

        return null === $place ? $message : $message.' ('.$place.')';
    }

    protected function source(string $source): string
    {
        if (!\in_array($source, self::SOURCES, true)) {
            throw new ToolCallException("source must be one of: ".implode(', ', self::SOURCES));
        }

        return $source;
    }
}
