<?php

declare(strict_types=1);

namespace WildcoreMcp;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Mcp\Exception\ToolCallException;

/**
 * Thin client for WildCore REST API (/api/v1). Every failure is turned into
 * ToolCallException, so the model gets a readable error instead of a protocol error.
 */
final class WildcoreClient
{
    private ClientInterface $http;

    public function __construct(private readonly Config $config, ?ClientInterface $http = null)
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $config->token) {
            $headers['X-Auth-Key'] = $config->token;
        }
        $this->http = $http ?? new Client([
            'base_uri' => $config->wildcoreUrl.'/api/v1/',
            'headers' => $headers,
            'timeout' => $config->timeout,
            'connect_timeout' => min(10, $config->timeout),
            'verify' => $config->verifySsl,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    /**
     * Joins path segments, encoding each one, so a model-supplied value can never
     * add segments, a query string or walk up with "..".
     */
    public static function path(string|int ...$segments): string
    {
        return implode('/', array_map(static function ($segment): string {
            $segment = (string) $segment;
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw new ToolCallException("Invalid path segment '$segment'");
            }

            return rawurlencode($segment);
        }, $segments));
    }

    /**
     * What the token owner can use: enabled components, role permissions (with defaults)
     * and whether the user is root (system/owner role, all permissions).
     *
     * @return array{components: list<string>, permissions: list<string>, root: bool, user: string}
     */
    public function capabilities(): array
    {
        $settings = $this->get('system/settings');
        $user = $settings['user'] ?? [];
        $role = $user['role'] ?? [];

        return [
            'components' => array_values($settings['components']['enabled'] ?? []),
            'permissions' => array_values($role['permissions'] ?? []),
            'root' => (int) ($user['id'] ?? 0) <= 0 || (int) ($role['id'] ?? 0) <= 0,
            'user' => (string) ($user['login'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, ['query' => self::query($query)]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $path, array $body = []): mixed
    {
        return $this->request('POST', $path, ['json' => (object) $body]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function put(string $path, array $body = []): mixed
    {
        return $this->request('PUT', $path, ['json' => (object) $body]);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path, []);
    }

    /**
     * Calls an API operation described in OpenAPI: query for every method, JSON body when given.
     *
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $options = ['query' => self::query($query)];
        if (null !== $body) {
            $options['json'] = (object) $body;
        }

        return $this->request(strtoupper($method), $path, $options);
    }

    /**
     * Downloads a non-API file from WildCore, e.g. the OpenAPI spec at /swagger/openapi.yaml.
     */
    public function raw(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url);
        } catch (GuzzleException $e) {
            throw new ToolCallException(\sprintf('Could not get %s from WildCore: %s', $url, $e->getMessage() ?: $e::class), previous: $e);
        }
        if (200 !== $response->getStatusCode()) {
            throw new ToolCallException(\sprintf('Could not get %s from WildCore (HTTP %d)', $url, $response->getStatusCode()));
        }

        return (string) $response->getBody();
    }

    /**
     * Returns "data" of WildCore response, or {data, meta} when the response has meta (pagination etc.).
     *
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options): mixed
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            throw new ToolCallException(\sprintf('Could not get an answer from WildCore for %s %s: %s', $method, $path, $e->getMessage() ?: $e::class), previous: $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);
        if (!\is_array($body)) {
            throw new ToolCallException(\sprintf('WildCore returned non-JSON response for %s %s (HTTP %d)', $method, $path, $status));
        }
        if ($status >= 400 || isset($body['error'])) {
            $error = $body['error']['description'] ?? $body['error']['message'] ?? $body['message'] ?? 'unknown error';
            throw new ToolCallException(\sprintf('WildCore API error for %s %s (HTTP %d): %s', $method, $path, $status, $error));
        }

        $data = \array_key_exists('data', $body) ? $body['data'] : $body;

        return empty($body['meta']) ? $data : ['data' => $data, 'meta' => $body['meta']];
    }

    /**
     * Drops unset parameters and serializes booleans the way WildCore expects.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function query(array $query): array
    {
        $result = [];
        foreach ($query as $key => $value) {
            if (null === $value || '' === $value) {
                continue;
            }
            $result[$key] = \is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return $result;
    }
}
