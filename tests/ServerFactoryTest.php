<?php

declare(strict_types=1);

namespace WildcoreMcp\Tests;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WildcoreMcp\Config;
use WildcoreMcp\ServerFactory;
use WildcoreMcp\ToolSearch;
use WildcoreMcp\Tools\Core\SwitcherCore;
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
        self::assertSame(['onts_registration', 'device-control'], $tools['ont_register']);
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
        self::assertArrayHasKey('api_read', $operator);
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

    public function testGroupsFollowWildcoreStructure(): void
    {
        $tools = $this->tools(new Config('http://wc', null, readOnly: false));

        self::assertSame('olts', $tools['olt_onts'][0]);
        self::assertSame('onts', $tools['onts_list'][0]);
        self::assertSame('events', $tools['events_list'][0]);
        self::assertSame('logs', $tools['logs_switcher_core'][0]);
        foreach (ServerFactory::toolClasses() as $class) {
            if (str_contains($class, '\\Components\\')) {
                self::assertSame($class::GROUP, $class::COMPONENT, "$class group must be its component key");
            } else {
                self::assertNull($class::COMPONENT, "$class is a core group");
            }
        }
    }

    public function testSecretsHiddenByDefault(): void
    {
        $off = $this->tools(new Config('http://wc', null));
        $on = $this->tools(new Config('http://wc', null, secretsEnabled: true));

        self::assertArrayNotHasKey('console_log_get', $off);
        self::assertSame(['console', 'read-only', 'secrets'], $on['console_log_get']);
        $offWithOtherDisabled = $this->tools(new Config('http://wc', null, disabledTags: ['macros']));
        self::assertArrayNotHasKey('console_log_get', $offWithOtherDisabled, 'DISABLED_TAGS must not enable secrets');
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

    public function testAssociativeArrayParametersAreJsonObjects(): void
    {
        $docBlocks = new DocBlockParser();
        $generator = new SchemaGenerator($docBlocks);
        $checked = 0;
        foreach ((new ServerFactory(new Config('http://wc', null, readOnly: false, secretsEnabled: true), new NullLogger()))->tools() as $tool) {
            $method = new \ReflectionMethod($tool['instance'], $tool['method']);
            $schema = $generator->generate($method);
            foreach ($docBlocks->getParamTags($docBlocks->parseDocBlock($method->getDocComment())) as $name => $tag) {
                if (str_starts_with((string) $docBlocks->getParamTypeString($tag), 'array<string')) {
                    $type = (array) $schema['properties'][ltrim($name, '$')]['type'];
                    self::assertContains('object', $type, "{$tool['name']}.$name must be a JSON object");
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(5, $checked);
    }

    public function testToolSearchMode(): void
    {
        $tools = (new ServerFactory(new Config('http://127.0.0.1:9', null, timeout: 2), new NullLogger()))->tools();
        $search = new ToolSearch($tools);

        $found = json_decode($search->search('onu signal')->content[0]->text, true);
        self::assertContains('ont_signals', array_column($found['tools'], 'name'));
        self::assertArrayHasKey('properties', $found['tools'][0]['input_schema']);

        try {
            // Empty nested object arrives as an empty PHP array - it must pass validation and reach WildCore
            $search->call('macros_preview', ['macros_id' => 1, 'device_id' => 1, 'params' => []]);
            self::fail('No WildCore is running, the call must fail on connection');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Could not get an answer from WildCore', $e->getMessage());
        }

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid arguments for device_get');
        $search->call('device_get', ['device_id' => 'not-a-number']);
    }

    public function testToolSearchRejectsUnknownTool(): void
    {
        $search = new ToolSearch((new ServerFactory(new Config('http://wc', null), new NullLogger()))->tools());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Unknown tool 'macros_execute'");
        $search->call('macros_execute', []);
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
