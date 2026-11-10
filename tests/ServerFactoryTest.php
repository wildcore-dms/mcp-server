<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WildcoreMcp\Config;
use WildcoreMcp\ServerFactory;
use WildcoreMcp\Tools\Api;
use WildcoreMcp\Tools\SwitcherCore;
use WildcoreMcp\WildcoreClient;

final class ServerFactoryTest extends TestCase
{
    public function testReadOnlyModeKeepsOnlyReadTools(): void
    {
        $all = $this->tools(new Config('http://wc', null, readOnly: false));
        $read = $this->tools(new Config('http://wc', null, readOnly: true));

        self::assertContains('macros_execute', array_keys($all));
        self::assertContains('swc_call', array_keys($all));
        self::assertContains('ont_signal_reset', array_keys($all));
        foreach ($read as $name => $tags) {
            self::assertSame('read-only', $tags[1], "$name must be read-only");
        }
        self::assertNotContains('macros_execute', array_keys($read));
        self::assertContains('macros_preview', array_keys($read));
    }

    public function testKinds(): void
    {
        $tools = $this->tools(new Config('http://wc', null, readOnly: false));

        self::assertSame(['macros', 'device-control'], $tools['macros_execute']);
        self::assertSame(['ont-registration', 'device-control'], $tools['ont_register']);
        self::assertSame(['switcher-core', 'device-control'], $tools['swc_call']);
        self::assertSame(['macros', 'config'], $tools['macros_delete']);
        self::assertSame(['onts', 'config'], $tools['ont_signal_reset']);
    }

    public function testDisabledTags(): void
    {
        $tools = $this->tools(new Config('http://wc', null, readOnly: false, disabledTags: ['macros', 'device-control']));

        self::assertEmpty(array_filter($tools, static fn ($tags) => \in_array('macros', $tags, true) || \in_array('device-control', $tags, true)));
        self::assertArrayHasKey('ont_registration_template_create', $tools);
    }

    public function testFiltersByPermissionsAndComponents(): void
    {
        $config = new Config('http://wc', null, readOnly: false);
        $operator = $this->tools($config, ['components' => ['olts'], 'permissions' => ['device_show', 'olts_info'], 'root' => false]);

        self::assertArrayHasKey('devices_list', $operator);
        self::assertArrayHasKey('ont_info', $operator);
        self::assertArrayHasKey('api_get', $operator);
        self::assertArrayNotHasKey('search', $operator, 'no global_search permission');
        self::assertArrayNotHasKey('ont_signal_reset', $operator, 'no device_iface_management permission');
        self::assertArrayNotHasKey('macros_list', $operator, 'macros component disabled');
        self::assertArrayNotHasKey('swc_read', $operator, 'switcher-core is root only');

        $noOlts = $this->tools($config, ['components' => [], 'permissions' => [], 'root' => true]);
        self::assertArrayHasKey('swc_call', $noOlts);
        self::assertArrayNotHasKey('olt_onts', $noOlts, 'root still needs enabled component');
        self::assertArrayNotHasKey('ont_register', $noOlts);
    }

    public function testBuildsServer(): void
    {
        $server = (new ServerFactory(new Config('http://wc', null), new NullLogger()))->build();

        self::assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testApiGetRejectsUnsafePaths(): void
    {
        $api = new Api(new WildcoreClient(new Config('http://127.0.0.1:9', null)), new Config('http://wc', null));
        foreach (['../system', 'device/../../x', 'http://evil/x', 'device?ip=1', 'a//b', 'a\\b', ''] as $path) {
            try {
                $api->get($path);
                self::fail("Path '$path' must be rejected");
            } catch (ToolCallException $e) {
                self::assertStringContainsString('Invalid path', $e->getMessage());
            }
        }
    }

    public function testSwcReadRejectsWriteModules(): void
    {
        $tools = new SwitcherCore(new WildcoreClient(new Config('http://127.0.0.1:9', null)), new Config('http://wc', null));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('use swc_call');
        $tools->read(1, 'reboot');
    }

    public function testReadModulesDoNotContainWriteModules(): void
    {
        foreach (SwitcherCore::READ_MODULES as $module) {
            self::assertDoesNotMatchRegularExpression('/^ctrl_|_ctrl$|^(reboot|save_config|clear_.*|console_command|multi_console_command|cable_diag|arp_ping)$/', $module);
        }
    }

    /**
     * @param array{components: list<string>, permissions: list<string>, root: bool}|null $capabilities
     *
     * @return array<string, list<string>> tool name => tags
     */
    private function tools(Config $config, ?array $capabilities = null): array
    {
        $result = [];
        foreach ((new ServerFactory($config, new NullLogger()))->tools($capabilities) as $tool) {
            $result[$tool['name']] = $tool['tags'];
        }

        return $result;
    }
}
