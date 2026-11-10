<?php

declare(strict_types=1);

namespace WildcoreMcp;

use Mcp\Exception\ToolCallException;
use Symfony\Component\Yaml\Yaml;

/**
 * WildCore API operations read from the OpenAPI spec the WildCore instance publishes
 * (by default {WILDCORE_URL}/swagger/openapi.yaml), so generic API tools always match
 * the installed WildCore version. Cached for CAPABILITIES_TTL seconds.
 */
final class OpenApiCatalog
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    /** Not under /api/v1 or not meant for API clients */
    private const SKIP_PATHS = '#^/(metrics|app-auth)(/|$)#';
    private const MAX_SCHEMA_DEPTH = 6;

    /** @var list<array<string, mixed>>|null */
    private ?array $operations = null;
    /** @var array<string, mixed> */
    private array $schemas = [];
    private int $loadedAt = 0;

    public function __construct(
        private readonly WildcoreClient $client,
        private readonly Config $config,
        private readonly ?string $spec = null,
    ) {
    }

    /**
     * @return list<array{id: string, method: string, path: string, summary: string, description: string, tag: string, component: string|null, parameters: list<mixed>, requestBody: mixed, responses: mixed}>
     */
    public function operations(): array
    {
        if (null === $this->operations || (null === $this->spec && time() - $this->loadedAt >= $this->config->capabilitiesTtl)) {
            $this->load();
        }

        return $this->operations;
    }

    public function byId(string $id): ?array
    {
        foreach ($this->operations() as $operation) {
            if ($operation['id'] === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Finds the operation a concrete path belongs to, e.g. GET /device/15 -> GET /device/{id}.
     * Literal path segments win over placeholders (/device/options before /device/{id}).
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/'.trim($path, '/');
        $best = null;
        $bestScore = -1;
        foreach ($this->operations() as $operation) {
            if ($operation['method'] !== $method) {
                continue;
            }
            $regex = '#^'.preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($operation['path'], '#')).'$#';
            if (!preg_match($regex, $path)) {
                continue;
            }
            $score = substr_count($operation['path'], '/') * 10 - substr_count($operation['path'], '{');
            if ($score > $bestScore) {
                $best = $operation;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Inlines $ref of components/schemas, cutting recursion at a fixed depth.
     */
    public function resolve(mixed $schema, int $depth = 0): mixed
    {
        if (!\is_array($schema)) {
            return $schema;
        }
        if (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strrpos($schema['$ref'], '/') + 1);
            if ($depth >= self::MAX_SCHEMA_DEPTH || !isset($this->schemas[$name])) {
                return ['$ref' => $name];
            }

            return $this->resolve($this->schemas[$name], $depth + 1);
        }
        $result = [];
        foreach ($schema as $key => $value) {
            if ('example' !== $key) {
                $result[$key] = $this->resolve($value, $depth);
            }
        }

        return $result;
    }

    private function load(): void
    {
        $raw = $this->spec ?? $this->client->raw($this->config->openapiUrl ?? $this->config->wildcoreUrl.'/swagger/openapi.yaml');
        try {
            $spec = Yaml::parse($raw);
        } catch (\Throwable $e) {
            throw new ToolCallException('WildCore OpenAPI spec could not be parsed: '.$e->getMessage(), previous: $e);
        }
        if (!\is_array($spec) || !isset($spec['paths']) || !\is_array($spec['paths'])) {
            throw new ToolCallException('WildCore OpenAPI spec has no paths');
        }

        $this->schemas = $spec['components']['schemas'] ?? [];
        $operations = [];
        foreach ($spec['paths'] as $path => $item) {
            if (!\is_array($item) || preg_match(self::SKIP_PATHS, (string) $path)) {
                continue;
            }
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (!\is_array($operation)) {
                    continue;
                }
                $operations[] = [
                    'id' => (string) ($operation['operationId'] ?? $method.' '.$path),
                    'method' => strtoupper($method),
                    'path' => (string) $path,
                    'summary' => (string) ($operation['summary'] ?? ''),
                    'description' => (string) ($operation['description'] ?? ''),
                    'tag' => (string) ($operation['tags'][0] ?? ''),
                    'component' => preg_match('#^/component/([^/]+)#', (string) $path, $m) ? $m[1] : null,
                    'parameters' => array_values(array_merge($item['parameters'] ?? [], $operation['parameters'] ?? [])),
                    'requestBody' => $operation['requestBody']['content']['application/json']['schema'] ?? null,
                    'responses' => $operation['responses']['200']['content']['application/json']['schema'] ?? null,
                ];
            }
        }
        $this->operations = $operations;
        $this->loadedAt = time();
    }
}
