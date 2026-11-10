<?php

declare(strict_types=1);

namespace WildcoreMcp;

/**
 * Server configuration read from environment variables.
 */
final class Config
{
    private const TRUTHY = ['true', '1', 'yes', 'on'];
    private const FALSY = ['false', '0', 'no', 'off'];

    /**
     * @param list<string> $disabledTags
     * @param list<string> $allowedHosts
     */
    public function __construct(
        public readonly string $wildcoreUrl,
        public readonly ?string $token,
        public readonly bool $verifySsl = true,
        public readonly int $timeout = 30,
        public readonly bool $readOnly = true,
        public readonly array $disabledTags = [],
        public readonly int $maxResultBytes = 200_000,
        public readonly ?string $httpBearerToken = null,
        public readonly array $allowedHosts = ['localhost', '127.0.0.1', '[::1]'],
        public readonly int $capabilitiesTtl = 300,
        public readonly string $logLevel = 'warning',
    ) {
    }

    /**
     * @param array<string, string|false> $env
     */
    public static function fromEnv(array $env): self
    {
        $get = static fn (string $name): ?string => isset($env[$name]) && '' !== trim((string) $env[$name]) ? trim((string) $env[$name]) : null;

        $url = $get('WILDCORE_URL') ?? throw new \InvalidArgumentException('WILDCORE_URL is required');
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException("WILDCORE_URL must start with http:// or https://, got '$url'");
        }

        return new self(
            wildcoreUrl: rtrim($url, '/'),
            token: $get('WILDCORE_TOKEN'),
            verifySsl: self::bool($get, 'WILDCORE_VERIFY_SSL', true),
            timeout: self::int($get, 'WILDCORE_TIMEOUT', 30),
            readOnly: self::bool($get, 'READ_ONLY_MODE', true),
            disabledTags: self::list($get('DISABLED_TAGS')),
            maxResultBytes: self::int($get, 'MAX_RESULT_BYTES', 200_000),
            httpBearerToken: $get('MCP_HTTP_BEARER_TOKEN'),
            allowedHosts: self::list($get('MCP_HTTP_ALLOWED_HOSTS')) ?: ['localhost', '127.0.0.1', '[::1]'],
            capabilitiesTtl: self::int($get, 'CAPABILITIES_TTL', 300),
            logLevel: strtolower($get('LOG_LEVEL') ?? 'warning'),
        );
    }

    private static function bool(\Closure $get, string $name, bool $default): bool
    {
        $value = $get($name);
        if (null === $value) {
            return $default;
        }
        $value = strtolower($value);
        if (\in_array($value, self::TRUTHY, true)) {
            return true;
        }
        if (\in_array($value, self::FALSY, true)) {
            return false;
        }
        throw new \InvalidArgumentException("$name must be a boolean (true/false, 1/0, yes/no, on/off), got '$value'");
    }

    private static function int(\Closure $get, string $name, int $default): int
    {
        $value = $get($name);
        if (null === $value) {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException("$name must be a positive integer, got '$value'");
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private static function list(?string $value): array
    {
        return array_values(array_filter(array_map(static fn ($v) => strtolower(trim($v)), explode(',', (string) $value))));
    }
}
