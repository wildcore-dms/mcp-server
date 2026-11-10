<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use WildcoreMcp\Config;
use WildcoreMcp\WildcoreClient;

final class WildcoreClientTest extends TestCase
{
    /** @var list<array{request: Request}> */
    private array $history = [];

    public function testPathEncodesEverySegment(): void
    {
        self::assertSame('component/olts/interfaces/ont/5/gpon0%2F1%3A5', WildcoreClient::path('component', 'olts', 'interfaces', 'ont', 5, 'gpon0/1:5'));
        self::assertSame('device/1%3Fx%3D1%23f', WildcoreClient::path('device', '1?x=1#f'));
    }

    public function testPathRejectsDotSegments(): void
    {
        $this->expectException(ToolCallException::class);
        WildcoreClient::path('device', '..');
    }

    public function testReturnsDataAndMeta(): void
    {
        $client = $this->client(
            new Response(200, [], '{"statusCode":200,"meta":null,"data":{"id":1}}'),
            new Response(200, [], '{"statusCode":200,"meta":{"page":1},"data":[1,2]}'),
        );

        self::assertSame(['id' => 1], $client->get('device/1'));
        self::assertSame(['data' => [1, 2], 'meta' => ['page' => 1]], $client->get('device', ['page' => 1, 'ip' => null, 'only' => true]));
        self::assertSame('page=1&only=1', $this->history[1]['request']->getUri()->getQuery());
        self::assertSame('key', $this->history[0]['request']->getHeaderLine('X-Auth-Key'));
    }

    public function testApiErrorBecomesToolError(): void
    {
        $client = $this->client(new Response(403, [], '{"statusCode":403,"error":{"type":"INSUFFICIENT_PRIVILEGES","description":"Insufficient rights"}}'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('(HTTP 403): Insufficient rights');
        $client->get('user');
    }

    public function testNonJsonAndTransportErrors(): void
    {
        $client = $this->client(
            new Response(502, [], '<html>Bad gateway</html>'),
            new ConnectException('Connection refused', new Request('GET', 'device')),
        );

        foreach (['non-JSON response', 'Could not get an answer from WildCore'] as $expected) {
            try {
                $client->get('device');
                self::fail('ToolCallException expected');
            } catch (ToolCallException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testCapabilities(): void
    {
        $settings = ['data' => [
            'components' => ['enabled' => ['olts', 'macros']],
            'user' => ['id' => 7, 'login' => 'oper', 'role' => ['id' => 8, 'permissions' => ['device_show', 'macros_execute']]],
        ]];
        $root = ['data' => ['components' => ['enabled' => []], 'user' => ['id' => 1, 'login' => 'admin', 'role' => ['id' => -2, 'permissions' => []]]]];
        $client = $this->client(new Response(200, [], json_encode($settings)), new Response(200, [], json_encode($root)));

        self::assertSame(
            ['components' => ['olts', 'macros'], 'permissions' => ['device_show', 'macros_execute'], 'root' => false, 'user' => 'oper'],
            $client->capabilities(),
        );
        self::assertTrue($client->capabilities()['root']);
    }

    private function client(Response|\Throwable ...$responses): WildcoreClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new WildcoreClient(
            new Config('http://wc', 'key'),
            new Client(['handler' => $stack, 'http_errors' => false, 'headers' => ['X-Auth-Key' => 'key']]),
        );
    }
}
