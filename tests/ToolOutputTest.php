<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use WildcoreMcp\Config;
use WildcoreMcp\Tools\Components\Macros;
use WildcoreMcp\Tools\Components\Pinger;
use WildcoreMcp\WildcoreClient;

/**
 * Tool results must be self-explanatory: no raw codes the model has to guess, no credentials.
 */
final class ToolOutputTest extends TestCase
{
    public function testPingerValuesAreExplained(): void
    {
        $statuses = ['data' => [['id' => 1, 'ip' => '10.0.0.1', 'status' => 42], ['id' => 2, 'ip' => '10.0.0.2', 'status' => 999], ['id' => 3, 'ip' => '10.0.0.3', 'status' => -100], ['id' => 4, 'ip' => '10.0.0.4', 'status' => 100]]];
        $pinger = new Pinger($this->client([new Response(200, [], (string) json_encode($statuses)), new Response(200, [], (string) json_encode($statuses))]), new Config('http://wc', null));

        $up = $this->json($pinger->statuses('up'))['items'];
        self::assertSame([
            ['id' => 1, 'ip' => '10.0.0.1', 'state' => 'up', 'reply_percent' => 42],
            ['id' => 2, 'ip' => '10.0.0.2', 'state' => 'up', 'tcp_only' => true],
            ['id' => 4, 'ip' => '10.0.0.4', 'state' => 'up', 'reply_percent' => 100],
        ], $up);
        self::assertSame([['id' => 3, 'ip' => '10.0.0.3', 'state' => 'down']], $this->json($pinger->statuses('down'))['items']);
    }

    public function testTemplateVariablesHideCredentials(): void
    {
        $variables = ['data' => [
            'device' => ['id' => 5, 'access' => ['id' => 2, 'name' => 'snmp-rw', 'params' => ['community' => 'secret']]],
            'user' => ['id' => 7, 'name' => 'Oper', 'login' => 'oper', 'role' => ['id' => 8, 'name' => 'Operator', 'permissions' => ['a', 'b']]],
            'params' => [],
        ]];
        $body = (string) json_encode($variables);

        $hidden = $this->json((new Macros($this->client([new Response(200, [], $body)]), new Config('http://wc', null)))->templateVariables(5));
        self::assertSame(['id' => 2, 'name' => 'snmp-rw'], $hidden['device']['access']);
        self::assertSame(['id' => 7, 'name' => 'Oper', 'login' => 'oper', 'role' => ['id' => 8, 'name' => 'Operator']], $hidden['user']);
        self::assertStringNotContainsString('secret', json_encode($hidden));

        $shown = $this->json((new Macros($this->client([new Response(200, [], $body)]), new Config('http://wc', null, secretsEnabled: true)))->templateVariables(5));
        self::assertSame('secret', $shown['device']['access']['params']['community']);
    }

    /**
     * @param list<Response> $responses
     */
    private function client(array $responses): WildcoreClient
    {
        return new WildcoreClient(new Config('http://wc', null), new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'base_uri' => 'http://wc/api/v1/', 'http_errors' => false]));
    }

    private function json(CallToolResult $result): mixed
    {
        return json_decode($result->content[0]->text, true);
    }
}
