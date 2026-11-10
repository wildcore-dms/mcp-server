<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Mcp\Capability\Attribute\Schema;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\ElementMetadataResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use WildcoreMcp\Tools\AbstractTools;

/**
 * TOOL_SEARCH_ENABLED mode: instead of ~100 tool definitions the client gets search_tools and call_tool.
 * The model finds tools by words, reads their input schema and calls them through call_tool;
 * the same filtered tool set, schema generation and argument validation as in the normal mode.
 */
final class ToolSearch
{
    private DocBlockParser $docBlocks;
    private SchemaGenerator $schemas;
    private SchemaValidator $validator;

    /** @var array<string, array{schema?: array<string, mixed>, description?: string}> */
    private array $cache = [];

    /**
     * @param list<array{name: string, tags: list<string>, instance: object, method: string, annotations: mixed}> $tools
     */
    public function __construct(private readonly array $tools, private readonly int $maxResults = 8)
    {
        $this->docBlocks = new DocBlockParser();
        $this->schemas = new SchemaGenerator($this->docBlocks);
        $this->validator = new SchemaValidator();
    }

    /**
     * Search WildCore tools by words in name, description and tags (group, kind). Returns matching tools with
     * their input schema; call them with call_tool. Groups: devices, interfaces, onts, logs, poller,
     * switcher-core, api and WildCore components (olts, macros, events, pinger, switches ...).
     *
     * @param string $query words to search, e.g. "onu signal", "logs errors", "port description"
     * @param int    $limit maximum number of tools
     */
    public function search(string $query, int $limit = 0): CallToolResult
    {
        $words = array_filter(preg_split('/[\s,]+/', mb_strtolower(trim($query))) ?: []);
        $scored = [];
        foreach ($this->tools as $tool) {
            $name = mb_strtolower($tool['name'].' '.implode(' ', $tool['tags']));
            $text = $name.' '.mb_strtolower($this->description($tool));
            $score = 0;
            foreach ($words as $word) {
                $score += str_contains($name, $word) ? 3 : (str_contains($text, $word) ? 1 : 0);
            }
            if ($score > 0 || !$words) {
                $scored[] = [$score, $tool];
            }
        }
        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0]);

        $result = array_map(fn ($item) => [
            'name' => $item[1]['name'],
            'tags' => $item[1]['tags'],
            'description' => $this->description($item[1]),
            'input_schema' => $this->schema($item[1]),
        ], \array_slice($scored, 0, $limit > 0 ? $limit : $this->maxResults));

        return new CallToolResult([new TextContent((string) json_encode(['total' => \count($scored), 'tools' => $result], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))]);
    }

    /**
     * Call a WildCore tool found with search_tools. Arguments are validated against its input schema.
     * Tools tagged device-control or config change the network or WildCore data - confirm with the user first.
     *
     * @param string               $name      tool name from search_tools
     * @param array<string, mixed> $arguments tool arguments
     */
    public function call(string $name, #[Schema(definition: [...AbstractTools::OBJECT, 'description' => 'tool arguments'])] array $arguments = []): CallToolResult
    {
        foreach ($this->tools as $tool) {
            if ($tool['name'] !== $name) {
                continue;
            }
            $errors = $this->validator->validateAgainstJsonSchema([] === $arguments ? new \stdClass() : $arguments, $this->schema($tool));
            if ($errors) {
                throw new ToolCallException("Invalid arguments for $name: ".implode('; ', array_map(static fn ($e) => trim($e['pointer'].' '.$e['message']), $errors)));
            }
            try {
                return $tool['instance']->{$tool['method']}(...$arguments);
            } catch (\TypeError|\ArgumentCountError $e) {
                throw new ToolCallException("Invalid arguments for $name: ".$e->getMessage(), previous: $e);
            } catch (\Error $e) {
                if (str_contains($e->getMessage(), 'Unknown named parameter')) {
                    throw new ToolCallException("Invalid arguments for $name: ".$e->getMessage(), previous: $e);
                }
                throw $e;
            }
        }
        throw new ToolCallException("Unknown tool '$name' - find tools with search_tools");
    }

    /**
     * @param array{name: string, instance: object, method: string} $tool
     *
     * @return array<string, mixed>
     */
    private function schema(array $tool): array
    {
        return $this->cache[$tool['name']]['schema'] ??= $this->schemas->generate(new \ReflectionMethod($tool['instance'], $tool['method']));
    }

    /**
     * @param array{name: string, instance: object, method: string} $tool
     */
    private function description(array $tool): string
    {
        return $this->cache[$tool['name']]['description'] ??= (string) ElementMetadataResolver::resolveDescription(new \ReflectionMethod($tool['instance'], $tool['method']), null, $this->docBlocks);
    }
}
