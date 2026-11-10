<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\LoggerInterface;
use WildcoreMcp\Tools\AbstractTools;

/**
 * Builds MCP server with tools filtered by READ_ONLY_MODE, DISABLED_TAGS and, when known,
 * by WildCore permissions of the token owner and enabled components (see Requires).
 *
 * Every tool gets two tags: its group (devices, onts, macros ...) and its kind:
 *  - read-only      - readOnlyHint: only reads data;
 *  - device-control - openWorldHint without readOnlyHint: changes equipment state;
 *  - config         - changes WildCore data (macros, templates, stored signals).
 */
final class ServerFactory
{
    public const VERSION = '0.1.0';

    /** @var list<class-string<AbstractTools>> */
    public const TOOL_CLASSES = [
        Tools\Devices::class,
        Tools\Interfaces::class,
        Tools\Onts::class,
        Tools\SwitcherCore::class,
        Tools\Macros::class,
        Tools\OntRegistration::class,
        Tools\Api::class,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly ?WildcoreClient $client = null,
    ) {
    }

    /**
     * @param array{components: list<string>, permissions: list<string>, root: bool}|null $capabilities null - do not filter by WildCore permissions
     */
    public function build(?array $capabilities = null, ?string $sessionDir = null): Server
    {
        $builder = Server::builder()
            ->setServerInfo('WildCore MCP Server', self::VERSION, 'MCP server for WildCore DMS')
            ->setInstructions($this->instructions())
            ->setLogger($this->logger)
            ->setPaginationLimit(200);
        if (null !== $sessionDir) {
            $builder->setSession(new FileSessionStore($sessionDir));
        }
        foreach ($this->tools($capabilities) as $tool) {
            $builder->addTool([$tool['instance'], $tool['method']], $tool['name'], annotations: $tool['annotations'], meta: ['tags' => $tool['tags']]);
        }

        return $builder->build();
    }

    /**
     * Tools left after filtering, used by build() and tests.
     *
     * @param array{components: list<string>, permissions: list<string>, root: bool}|null $capabilities
     *
     * @return list<array{name: string, tags: list<string>, instance: AbstractTools, method: string, annotations: mixed}>
     */
    public function tools(?array $capabilities = null): array
    {
        $client = $this->client ?? new WildcoreClient($this->config);
        $result = [];
        foreach (self::TOOL_CLASSES as $class) {
            $instance = new $class($client, $this->config);
            $reflection = new \ReflectionClass($class);
            $classRequires = ($reflection->getAttributes(Requires::class)[0] ?? null)?->newInstance();
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attribute = $method->getAttributes(McpTool::class)[0] ?? null;
                if (null === $attribute) {
                    continue;
                }
                /** @var McpTool $tool */
                $tool = $attribute->newInstance();
                $kind = self::kind($tool);
                $tags = [$class::GROUP, $kind];
                if (($this->config->readOnly && 'read-only' !== $kind) || array_intersect($tags, $this->config->disabledTags)) {
                    continue;
                }
                $requires = ($method->getAttributes(Requires::class)[0] ?? null)?->newInstance();
                if (null !== $capabilities && !self::isAllowed($capabilities, $classRequires, $requires)) {
                    continue;
                }
                $result[] = [
                    'name' => $tool->name ?? $method->getName(),
                    'tags' => $tags,
                    'instance' => $instance,
                    'method' => $method->getName(),
                    'annotations' => $tool->annotations,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array{components: list<string>, permissions: list<string>, root: bool} $capabilities
     */
    private static function isAllowed(array $capabilities, ?Requires $class, ?Requires $method): bool
    {
        $components = array_merge($class?->components ?? [], $method?->components ?? []);
        if (array_diff($components, $capabilities['components'])) {
            return false;
        }
        if ($capabilities['root']) {
            return true;
        }
        if ($class?->rootOnly || $method?->rootOnly) {
            return false;
        }
        $permissions = $method?->permissions ?: ($class?->permissions ?? []);

        return !$permissions || array_intersect($permissions, $capabilities['permissions']);
    }

    private static function kind(McpTool $tool): string
    {
        $annotations = $tool->annotations;
        if (true === $annotations?->readOnlyHint) {
            return 'read-only';
        }

        return true === $annotations?->openWorldHint ? 'device-control' : 'config';
    }

    private function instructions(): string
    {
        $text = 'Tools for WildCore DMS - network management system for switches, OLTs and routers: '
            .'devices, interfaces, ONUs with optical signal levels, switcher-core modules, macros and ONU registration. '
            .'Access is limited by permissions and device groups of the WildCore user owning the API token. '
            .'Prefer source "cache" for switcher-core based tools, use "device" only when fresh data is required.';

        return $this->config->readOnly
            ? $text.' The server is in read-only mode.'
            : $text.' Before macros_execute, ont_register or swc_call show the commands/action to the user (use *_preview tools) and get explicit confirmation.';
    }
}
