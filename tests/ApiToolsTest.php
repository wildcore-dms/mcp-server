<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use WildcoreMcp\Config;
use WildcoreMcp\OpenApiCatalog;
use WildcoreMcp\Tools\Core\Api;
use WildcoreMcp\WildcoreClient;

final class ApiToolsTest extends TestCase
{
    private const SPEC = <<<'YAML'
openapi: 3.0.0
paths:
  /device:
    get: { operationId: listDevices, tags: [device], summary: Get device list }
  /device/{id}:
    get: { operationId: getDevice, tags: [device], summary: Get device by ID, parameters: [{ name: id, in: path, required: true, schema: { type: integer } }] }
    put: { operationId: editDevice, tags: [device], summary: Edit device, requestBody: { content: { application/json: { schema: { $ref: '#/components/schemas/Device' } } } } }
  /device/options:
    get: { operationId: deviceOptions, tags: [device], summary: Devices for options }
  /logs/actions:
    post: { operationId: filterActions, tags: [system-logs], summary: Filter system action logs }
  /device-access:
    get: { operationId: listAccess, tags: [device-access], summary: List device access profiles }
  /switcher-core/{storage}/{module}/{device_id}:
    get: { operationId: swcGet, tags: [switcher-core], summary: Execute module }
  /component/pinger/logs/{device_id}:
    get: { operationId: pingerLogs, tags: [pinger], summary: Get pinger down logs }
  /component/switches/interfaces/{device}/cable_diag:
    get: { operationId: cableDiag, tags: [switches], summary: Run cable diagnostics }
  /metrics:
    get: { operationId: metrics, tags: [system], summary: Prometheus metrics }
components:
  schemas:
    Device:
      type: object
      properties: { name: { type: string, example: sw1 }, group: { $ref: '#/components/schemas/Group' } }
    Group:
      type: object
      properties: { id: { type: integer } }
YAML;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public function testSearchListsOperationsWithAccessAndSkipsDisabledComponents(): void
    {
        $result = $this->json($this->api(components: ['switches'])->search('device'));

        $byId = array_column($result['operations'], 'access', 'operation_id');
        self::assertSame('read', $byId['listDevices']);
        self::assertSame('write', $byId['editDevice']);
        self::assertSame('secret', $byId['listAccess']);
        self::assertArrayNotHasKey('swcGet', $byId, 'denied operations are not listed');

        $all = array_column($this->json($this->api(components: ['switches'])->search(''))['operations'], 'operation_id');
        self::assertNotContains('pingerLogs', $all, 'pinger component is disabled');
        self::assertNotContains('metrics', $all, '/metrics is not under /api/v1');
        self::assertContains('cableDiag', $all);
    }

    public function testDescribeResolvesRefs(): void
    {
        $result = $this->json($this->api()->describe('editDevice'));

        self::assertSame('api_call', $result['call_with']);
        self::assertSame(['id' => ['type' => 'integer']], $result['request_body']['properties']['group']['properties']);
        self::assertArrayNotHasKey('example', $result['request_body']['properties']['name']);
        self::assertSame('getDevice', $this->json($this->api()->describe('GET /device/{id}'))['operation_id']);
        self::assertSame('getDevice', $this->json($this->api()->describe('get device/15'))['operation_id']);
    }

    public function testReadCallsMatchedOperation(): void
    {
        $api = $this->api(responses: [new Response(200, [], '{"data":{"id":15}}'), new Response(200, [], '{"data":[]}'), new Response(200, [], '{"data":[]}')]);

        self::assertSame(['id' => 15], $this->json($api->read('device/15')));
        $api->read('logs/actions', 'POST', [], ['start' => '2026-11-10 00:00:00', 'stop' => '2026-11-10 23:59:59']);
        $api->read('device/options');

        self::assertSame('/api/v1/device/15', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('POST', $this->history[1]['request']->getMethod());
        self::assertSame('/api/v1/device/options', $this->history[2]['request']->getUri()->getPath());
    }

    public function testAccessIsEnforced(): void
    {
        $api = $this->api();
        $cases = [
            ['read', ['device/15', 'PUT'], 'not a read operation'],
            ['read', ['component/switches/interfaces/1/cable_diag'], 'not a read operation'],
            ['read', ['switcher-core/device/reboot/1'], 'use swc_read / swc_call'],
            ['call', ['GET', 'switcher-core/device/reboot/1'], 'use swc_read / swc_call'],
            ['read', ['device-access'], 'ENABLE_SECRETS=false'],
            ['call', ['GET', 'device-access'], 'ENABLE_SECRETS=false'],
            ['read', ['component/pinger/logs/1'], 'is not enabled'],
            ['read', ['no/such/path'], 'No WildCore API operation'],
        ];
        foreach (['../system', 'device/../../x', 'http://evil/x', 'device?ip=1', 'a//b', 'a\\b', ''] as $path) {
            $cases[] = ['read', [$path], 'Invalid path'];
        }
        foreach ($cases as [$tool, $args, $message]) {
            try {
                $api->{$tool}(...$args);
                self::fail("$tool(".implode(', ', $args).") must be rejected");
            } catch (ToolCallException $e) {
                self::assertStringContainsString($message, $e->getMessage(), implode(', ', $args));
            }
        }
        self::assertSame([], $this->history, 'rejected calls never reach WildCore');
    }

    public function testSecretsAllowedWhenEnabled(): void
    {
        $api = $this->api(responses: [new Response(200, [], '{"data":[{"id":1}]}')], secrets: true);

        self::assertSame([['id' => 1]], $this->json($api->read('device-access')));
    }

    /**
     * @param list<string>   $components
     * @param list<Response> $responses
     */
    private function api(array $components = ['switches'], array $responses = [], bool $secrets = false): Api
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $config = new Config('http://wc', null, secretsEnabled: $secrets);
        $client = new WildcoreClient($config, new Client(['handler' => $stack, 'base_uri' => 'http://wc/api/v1/', 'http_errors' => false]));
        $api = new Api($client, $config, ['components' => $components, 'permissions' => [], 'root' => true]);
        (new \ReflectionProperty(Api::class, 'catalog'))->setValue($api, new OpenApiCatalog($client, $config, self::SPEC));

        return $api;
    }

    private function json(CallToolResult $result): mixed
    {
        return json_decode($result->content[0]->text, true);
    }
}
