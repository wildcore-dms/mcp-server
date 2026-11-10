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

    /** JSON schema of macro parameters - the same format is used by ONU registration templates */
    public const PARAMETERS = [
        'type' => 'array',
        'description' => 'form fields the user fills in before execution; values are available in the template as {{params.<key>}}',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'name', 'type'],
            'additionalProperties' => true,
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'property name, used in the template as {{params.<key>}}'],
                'name' => ['type' => 'string', 'description' => 'label shown to the user'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['select_from_predefined', 'select_from_variable', 'input_variable', 'input_string'],
                    'description' => 'select_from_predefined - dropdown of variants_list; select_from_variable - dropdown of items of an array variable (the whole item object becomes the value); input_variable - input prefilled from a scalar variable; input_string - free input',
                ],
                'required' => ['type' => 'boolean'],
                'variants_list' => ['type' => 'string', 'description' => 'select_from_predefined: values, one per line'],
                'source_parameter_key' => ['type' => 'string', 'description' => 'select_from_variable: path of an array variable, e.g. interfaces_list, data.vlans, data.pon_profiles.line; input_variable: path of a scalar variable'],
                'item_name' => ['type' => 'string', 'description' => 'select_from_variable: label of an item, JS template, e.g. "${item.name} (${item.id})"; default ${item.name}'],
                'item_filter' => ['type' => 'string', 'description' => 'select_from_variable: JS condition to show an item, e.g. "item.id != 0"'],
                'visible_if' => ['type' => 'string', 'description' => 'JS condition to show the field, e.g. "params.action === \'Add\'" or "!iface"'],
                'regular_expr' => ['type' => 'string', 'description' => 'input_*: regular expression the value must match'],
                'default_value' => ['type' => 'string', 'description' => 'input_string: default value'],
            ],
        ],
    ];

    private const DISPLAY_FOR = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['DEVICE', 'PORT', 'PON', 'ONU']], 'description' => 'where the macro is offered: DEVICE (device page), PORT (switch port), PON (PON port), ONU'];
    private const IDS = ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]];

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
        #[Schema(definition: [...self::OBJECT, 'description' => 'values of macro parameters by key; for select_from_variable pass the whole item object of the source array, for select_from_predefined one of variants_list'])] array $params = [],
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
        #[Schema(definition: [...self::OBJECT, 'description' => 'values of macro parameters by key; for select_from_variable pass the whole item object of the source array, for select_from_predefined one of variants_list'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->safeTemplateVariables($this->api->post('component/macros/variables', $this->body($macros_id, $device_id, $interface_id, $interface_bind_key, $params, $source))));
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
        #[Schema(definition: [...self::OBJECT, 'description' => 'values of macro parameters by key; for select_from_variable pass the whole item object of the source array, for select_from_predefined one of variants_list'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/execute', $this->body($macros_id, $device_id, $interface_id, $interface_bind_key, $params, $source) + ['preview' => false]));
    }

    /**
     * Variables a macro template gets for a device (and interface) - for writing a new macro before it exists:
     * params, iface (the selected interface), device (incl. device.params custom parameters), interfaces_list,
     * user, data.<module> (switcher-core data loaded for DEVICE/PORT/PON/ONU), error.<module>, free_onts (OLT).
     *
     * @param int                  $device_id    sample device ID
     * @param int|null             $interface_id sample interface ID (port, PON port or ONU) - gives iface and interface data
     * @param array<string, mixed> $params       sample values of parameters
     * @param string               $source       data source for device data
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_template_variables', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function templateVariables(
        int $device_id,
        ?int $interface_id = null,
        #[Schema(definition: [...self::OBJECT, 'description' => 'sample values of parameters'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->safeTemplateVariables($this->api->post('component/macros/control/variables', $this->draft($device_id, $interface_id, $params, $source))));
    }

    /**
     * Render a macro template for a device without saving or executing it - returns the exact commands.
     * Use it to check a template while writing or changing a macro. Twig runs with strict variables:
     * a missing variable is an error, use "is defined" / default() for optional data.
     * Put every {% %} tag on its own line (a newline after a tag is removed). A rendered <exception "..."> line
     * means the execution would stop with that message.
     *
     * @param string               $template     Twig template of CLI commands
     * @param int                  $device_id    sample device ID
     * @param int|null             $interface_id sample interface ID
     * @param array<string, mixed> $params       values of parameters, as the form would send them
     * @param string               $source       data source for device data
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_template_preview', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function templatePreview(
        string $template,
        int $device_id,
        ?int $interface_id = null,
        #[Schema(definition: [...self::OBJECT, 'description' => 'values of parameters'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post('component/macros/control/preview', $this->draft($device_id, $interface_id, $params, $source) + ['template' => $template]));
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
     * Create a macro: a Twig template of CLI commands executed on devices of the given models.
     * Prepare it first: device_models_list / user_roles_list for IDs, macros_template_variables for the
     * variables of a sample device, macros_template_preview to check the rendered commands.
     *
     * @param string                          $name           macro name
     * @param string                          $template       Twig template of CLI commands, one command per line; special commands <sleep x>, <exception "msg">, <cr>, <confirm="y">, <confirm-if="y","prompt">, <prompt="text">, <stream_timeout=x>
     * @param list<string>                    $display_for    where the macro is offered: DEVICE, PORT, PON, ONU
     * @param list<array{id: int}>            $models         device models the macro works on, see device_models_list
     * @param list<array{id: int}>            $user_roles     roles allowed to execute it, see user_roles_list
     * @param list<array<string, mixed>>      $parameters     form fields, see the schema
     * @param string                          $description    description shown to the user
     * @param string                          $display_output which command output to show: no, all, last
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_create', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function create(
        string $name,
        string $template,
        #[Schema(definition: self::DISPLAY_FOR)] array $display_for,
        #[Schema(definition: [...self::IDS, 'description' => 'device models, see device_models_list'])] array $models,
        #[Schema(definition: [...self::IDS, 'description' => 'roles allowed to execute, see user_roles_list'])] array $user_roles,
        #[Schema(definition: self::PARAMETERS)] array $parameters = [],
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
     * @param list<array<string, mixed>>|null     $parameters     form fields (replaces all parameters), see the schema
     * @param string|null                         $description    description
     * @param string|null                         $display_output which command output to show: no, all, last
     */
    #[Requires(permissions: ['macros_edit'])]
    #[McpTool(name: 'macros_update', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    public function update(
        int $macros_id,
        ?string $name = null,
        ?string $template = null,
        #[Schema(definition: [...self::DISPLAY_FOR, 'type' => ['array', 'null']])] ?array $display_for = null,
        #[Schema(definition: [...self::IDS, 'type' => ['array', 'null']])] ?array $models = null,
        #[Schema(definition: [...self::IDS, 'type' => ['array', 'null']])] ?array $user_roles = null,
        #[Schema(definition: [...self::PARAMETERS, 'type' => ['array', 'null']])] ?array $parameters = null,
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
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function draft(int $device_id, ?int $interface_id, array $params, string $source): array
    {
        $body = ['device' => ['id' => $device_id], 'params' => (object) $params, 'from' => $this->source($source)];
        if (null !== $interface_id) {
            $body['interface'] = ['id' => $interface_id];
        }

        return $body;
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
