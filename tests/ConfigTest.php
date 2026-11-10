<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WildcoreMcp\Config;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromEnv(['WILDCORE_URL' => 'https://wc.example/']);

        self::assertSame('https://wc.example', $config->wildcoreUrl);
        self::assertNull($config->token);
        self::assertTrue($config->readOnly);
        self::assertTrue($config->verifySsl);
        self::assertSame([], $config->disabledTags);
        self::assertSame(['localhost', '127.0.0.1', '[::1]'], $config->allowedHosts);
    }

    public function testParsesValues(): void
    {
        $config = Config::fromEnv([
            'WILDCORE_URL' => 'http://wc',
            'WILDCORE_TOKEN' => ' key ',
            'READ_ONLY_MODE' => 'off',
            'WILDCORE_VERIFY_SSL' => 'No',
            'DISABLED_TAGS' => 'Macros, device-control,,',
            'MCP_HTTP_ALLOWED_HOSTS' => 'mcp.example',
            'WILDCORE_TIMEOUT' => '5',
        ]);

        self::assertSame('key', $config->token);
        self::assertFalse($config->readOnly);
        self::assertFalse($config->verifySsl);
        self::assertSame(['macros', 'device-control'], $config->disabledTags);
        self::assertSame(['mcp.example'], $config->allowedHosts);
        self::assertSame(5, $config->timeout);
    }

    /**
     * @param array<string, string> $env
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsInvalid(array $env, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Config::fromEnv($env + ['WILDCORE_URL' => 'http://wc']);
    }

    public static function invalidProvider(): iterable
    {
        yield 'no url' => [['WILDCORE_URL' => ' '], 'WILDCORE_URL is required'];
        yield 'bad scheme' => [['WILDCORE_URL' => 'ftp://wc'], 'must start with http'];
        yield 'bad bool' => [['READ_ONLY_MODE' => 'maybe'], 'READ_ONLY_MODE must be a boolean'];
        yield 'bad int' => [['WILDCORE_TIMEOUT' => '0'], 'WILDCORE_TIMEOUT must be a positive integer'];
    }
}
