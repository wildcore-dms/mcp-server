<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Components;

use WildcoreMcp\Tools\AbstractTools;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\WildcoreClient;

/**
 * Macros: command templates executed on devices. Reading and execution need macros_execute
 * permission in WildCore, management (macros_manage_*, create/update/delete) needs macros_edit.
 */
#[Requires(permissions: ['macros_execute'])]
final class Macros extends AbstractTools
{
    public const GROUP = 'macros';
    public const COMPONENT = 'macros';

    private const FIELDS = ['name', 'description', 'display_for', 'display_output', 'models', 'user_roles', 'template', 'parameters'];

    /**
     * List macros available for device (and optionally its interface) to the current user.
     *
     * @param int         $device_id          device ID
     * @param int|null    $interface_id       interface ID, for port/PON/ONU macros
     * @param string|null $interface_bind_key interface bind key, alternative to interface_id
     */
    #[McpTool(name: 'macros_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(int $device_id, ?int $interface_id = null, ?string $interface_bind_key = null): CallToolResult
    {
        return $this->out($this->api->get('component/macros/list', compact('device_id', 'interface_id', 'interface_bind_key')));
    }

    /**
     * Get macros by ID: template, parameters and where it can be executed.
     *
     * @param int $macros_id macros ID
     */
    #[McpTool(name: 'macros_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function get(int $macros_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'macros', 'macro', $macros_id)));
    }

    /**
     * Render macros for device and return commands that would be executed, without executing them.
     * Always call it and show commands to the user before macros_execute.
     *
     * @param int                  $macros_id          macros ID
     * @param int                  $device_id          device ID
     * @param int|null             $interface_id       interface ID, for port/PON/ONU macros
     * @param string|null          $interface_bind_key interface bind key, alternative to interface_id
     * @param array<string, mixed> $params             values of macros parameters
     * @param string               $source             data source for template variables
     */
    #[McpTool(name: 'macros_preview', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function preview(
        int $macros_id,
        int $device_id,
        ?int $interface_id = null,
        ?string $interface_bind_key = null,
        array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/execute', $this->body($macros_id, $device_id, $interface_id, $interface_bind_key, $params, $source) + ['preview' => true]));
    }

    /**
     * Get variables available in macros template for device/interface (for writing or debugging templates).
     *
     * @param int                  $macros_id          macros ID
     * @param int                  $device_id          device ID
     * @param int|null             $interface_id       interface ID
     * @param string|null          $interface_bind_key interface bind key, alternative to interface_id
     * @param array<string, mixed> $params             values of macros parameters
     * @param string               $source             data source for template variables
     */
    #[McpTool(name: 'macros_variables', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function variables(
        int $macros_id,
        int $device_id,
        ?int $interface_id = null,
        ?string $interface_bind_key = null,
        array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/variables', $this->body($macros_id, $device_id, $interface_id, $interface_bind_key, $params, $source)));
    }

    /**
     * Execute macros on device - sends rendered commands to the device.
     * Call macros_preview first and get explicit user confirmation of the commands.
     *
     * @param int                  $macros_id          macros ID
     * @param int                  $device_id          device ID
     * @param int|null             $interface_id       interface ID, for port/PON/ONU macros
     * @param string|null          $interface_bind_key interface bind key, alternative to interface_id
     * @param array<string, mixed> $params             values of macros parameters
     * @param string               $source             data source for template variables
     */
    #[McpTool(name: 'macros_execute', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function execute(
        int $macros_id,
        int $device_id,
        ?int $interface_id = null,
        ?string $interface_bind_key = null,
        array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/execute', $this->body($macros_id, $device_id, $interface_id, $interface_bind_key, $params, $source) + ['preview' => false]));
    }

    /**
     * List all macros for management (requires macros_edit permission).
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_manage_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function manageList(): CallToolResult
    {
        return $this->out($this->api->get('component/macros/control'));
    }

    /**
     * Get macros with full management data: models, roles, parameters, template.
     *
     * @param int $macros_id macros ID
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_manage_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function manageGet(int $macros_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path('component', 'macros', 'control', $macros_id)));
    }

    /**
     * Create macros. Template uses WildCore macros syntax, see macros_variables for available variables.
     *
     * @param string                          $name           macros name
     * @param string                          $template       commands template
     * @param list<string>                    $display_for    where macros is shown: DEVICE, PORT, PON, ONU
     * @param list<array{id: int}>            $models         device models the macros is for
     * @param list<array{id: int}>            $user_roles     roles allowed to execute
     * @param list<array<string, mixed>>      $parameters     user input parameters of template
     * @param string                          $description    description
     * @param string                          $display_output which command output to show: no, all, last
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_create', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function create(
        string $name,
        string $template,
        array $display_for,
        array $models,
        array $user_roles,
        array $parameters = [],
        string $description = '',
        #[Schema(enum: ['no', 'all', 'last'])] string $display_output = 'all',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/control', compact(...self::FIELDS)));
    }

    /**
     * Update macros. Only passed fields are changed.
     *
     * @param int                                 $macros_id      macros ID
     * @param string|null                         $name           macros name
     * @param string|null                         $template       commands template
     * @param list<string>|null                   $display_for    where macros is shown: DEVICE, PORT, PON, ONU
     * @param list<array{id: int}>|null           $models         device models the macros is for
     * @param list<array{id: int}>|null           $user_roles     roles allowed to execute
     * @param list<array<string, mixed>>|null     $parameters     user input parameters of template
     * @param string|null                         $description    description
     * @param string|null                         $display_output which command output to show: no, all, last
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_update', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    public function update(
        int $macros_id,
        ?string $name = null,
        ?string $template = null,
        ?array $display_for = null,
        ?array $models = null,
        ?array $user_roles = null,
        ?array $parameters = null,
        ?string $description = null,
        #[Schema(enum: ['no', 'all', 'last'])] ?string $display_output = null,
    ): CallToolResult {
        $changes = array_filter(compact(...self::FIELDS), static fn ($v) => null !== $v);

        return $this->out($this->api->put(WildcoreClient::path('component', 'macros', 'control', $macros_id), $changes));
    }

    /**
     * Clone macros, the copy gets a new ID.
     *
     * @param int $macros_id macros ID
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_clone', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function clone(int $macros_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path('component', 'macros', 'control', 'clone', $macros_id)));
    }

    /**
     * Delete macros.
     *
     * @param int $macros_id macros ID
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_delete', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    public function delete(int $macros_id): CallToolResult
    {
        return $this->out($this->api->delete(WildcoreClient::path('component', 'macros', 'control', $macros_id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $macros_id, int $device_id, ?int $interface_id = null, ?string $interface_bind_key = null, array $params = [], string $source = 'cache'): array
    {
        $body = [
            'macros' => ['id' => $macros_id],
            'device' => ['id' => $device_id],
            'params' => (object) $params,
            'from' => $this->source($source),
        ];
        if (null !== $interface_id) {
            $body['interface'] = ['id' => $interface_id];
        } elseif (null !== $interface_bind_key) {
            $body['interface'] = ['bind_key' => $interface_bind_key];
        }

        return $body;
    }
}
