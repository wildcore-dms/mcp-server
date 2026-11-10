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
 * Registration of new ONUs on OLT by registration macros (onts_registration component).
 */
#[Requires(permissions: ['unregistered_onts'])]
final class OntsRegistration extends AbstractTools
{
    public const GROUP = 'onts_registration';
    public const COMPONENT = 'onts_registration';

    private const BASE = ['component', 'onts_registration'];
    private const FIELDS = ['name', 'models', 'template', 'parameters', 'enabled'];

    /**
     * List unregistered ONUs waiting for registration, on one OLT or on all OLTs.
     *
     * @param int|null $device_id OLT device ID, empty - all OLTs
     * @param string   $source    cache - cached data refreshed if stale; store - only cache; device - live request
     */
    #[McpTool(name: 'onts_unregistered', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function unregistered(?int $device_id = null, #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        $path = null === $device_id
            ? WildcoreClient::path(...self::BASE, ...['unregistered'])
            : WildcoreClient::path(...self::BASE, ...['unregistered', $device_id]);

        return $this->out($this->api->get($path, ['from' => $this->source($source)]));
    }

    /**
     * Find unregistered ONU on OLT by serial number or MAC address.
     *
     * @param int    $device_id OLT device ID
     * @param string $ident     ONU serial number or MAC address
     */
    #[McpTool(name: 'ont_find_unregistered', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function findByIdent(int $device_id, #[Schema(minLength: 1)] string $ident): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path(...self::BASE, ...['by-ident', $device_id, $ident])));
    }

    /**
     * Get registration macros that will be used for OLT, with its parameters.
     *
     * @param int $device_id OLT device ID
     */
    #[McpTool(name: 'ont_registration_macros', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function macroByDevice(int $device_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path(...self::BASE, ...['by-device', $device_id])));
    }

    /**
     * Render registration commands for ONU without executing them.
     * Always call it and show commands to the user before ont_register.
     *
     * @param int                  $device_id OLT device ID
     * @param array<string, mixed> $ont       unregistered ONU record exactly as returned by onts_unregistered
     * @param array<string, mixed> $params    values of registration macros parameters
     * @param string               $source    data source for template variables
     */
    #[Requires(permissions: ['unregistered_onts', 'unregistered_onts_preview'])]
    #[McpTool(name: 'ont_registration_preview', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function preview(int $device_id, #[Schema(definition: [...self::OBJECT, 'description' => 'unregistered ONU record as returned by onts_unregistered'])] array $ont, #[Schema(definition: [...self::OBJECT, 'description' => 'values of template parameters by key; for select_from_variable pass the whole item object (e.g. a profile from data.pon_profiles.line)'])] array $params = [], #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->api->post(WildcoreClient::path(...self::BASE, ...['execute']), $this->body($device_id, $ont, $params, $source) + ['preview' => true]));
    }

    /**
     * Get variables available in registration template for ONU (free ONU IDs, ports, profiles ...).
     *
     * @param int                  $device_id OLT device ID
     * @param array<string, mixed> $ont       unregistered ONU record as returned by onts_unregistered
     * @param array<string, mixed> $params    values of registration macros parameters
     * @param string               $source    data source for template variables
     */
    #[Requires(permissions: ['unregistered_onts', 'unregistered_onts_preview'])]
    #[McpTool(name: 'ont_registration_variables', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function variables(int $device_id, #[Schema(definition: [...self::OBJECT, 'description' => 'unregistered ONU record as returned by onts_unregistered'])] array $ont, #[Schema(definition: [...self::OBJECT, 'description' => 'values of template parameters by key; for select_from_variable pass the whole item object (e.g. a profile from data.pon_profiles.line)'])] array $params = [], #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->safeTemplateVariables($this->api->post(WildcoreClient::path(...self::BASE, ...['variables']), $this->body($device_id, $ont, $params, $source))));
    }

    /**
     * Register ONU on OLT - executes registration commands on the device.
     * Call ont_registration_preview first and get explicit user confirmation of the commands.
     *
     * @param int                  $device_id OLT device ID
     * @param array<string, mixed> $ont       unregistered ONU record exactly as returned by onts_unregistered
     * @param array<string, mixed> $params    values of registration macros parameters
     * @param string               $source    data source for template variables
     */
    #[McpTool(name: 'ont_register', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function register(int $device_id, #[Schema(definition: [...self::OBJECT, 'description' => 'unregistered ONU record as returned by onts_unregistered'])] array $ont, #[Schema(definition: [...self::OBJECT, 'description' => 'values of template parameters by key; for select_from_variable pass the whole item object (e.g. a profile from data.pon_profiles.line)'])] array $params = [], #[Schema(enum: self::SOURCES)] string $source = 'cache'): CallToolResult
    {
        return $this->out($this->api->post(WildcoreClient::path(...self::BASE, ...['execute']), $this->body($device_id, $ont, $params, $source) + ['preview' => false]));
    }

    /**
     * Variables a registration template gets for an unregistered ONU - for writing or debugging a template.
     *
     * @param int                  $device_id OLT device ID
     * @param array<string, mixed> $ont       unregistered ONU record exactly as returned by onts_unregistered
     * @param array<string, mixed> $params    sample values of parameters
     * @param string               $source    data source for device data
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_variables', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function templateVariables(
        int $device_id,
        #[Schema(definition: [...self::OBJECT, 'description' => 'unregistered ONU record as returned by onts_unregistered'])] array $ont,
        #[Schema(definition: [...self::OBJECT, 'description' => 'sample values of parameters'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->safeTemplateVariables($this->api->post(WildcoreClient::path(...self::BASE, ...['control', 'variables']), $this->body($device_id, $ont, $params, $source))));
    }

    /**
     * Render a registration template for an unregistered ONU without saving or executing it.
     *
     * @param string               $template  Twig template of registration commands
     * @param int                  $device_id OLT device ID
     * @param array<string, mixed> $ont       unregistered ONU record exactly as returned by onts_unregistered
     * @param array<string, mixed> $params    values of parameters
     * @param string               $source    data source for device data
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_preview', annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function templatePreview(
        string $template,
        int $device_id,
        #[Schema(definition: [...self::OBJECT, 'description' => 'unregistered ONU record as returned by onts_unregistered'])] array $ont,
        #[Schema(definition: [...self::OBJECT, 'description' => 'values of parameters'])] array $params = [],
        #[Schema(enum: self::SOURCES)] string $source = 'cache',
    ): CallToolResult {
        return $this->out($this->api->post(WildcoreClient::path(...self::BASE, ...['control', 'preview']), $this->body($device_id, $ont, $params, $source) + ['template' => $template]));
    }

    /**
     * List registration macros (templates) for management (requires unregistered_onts_config permission).
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_templates', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function templates(): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path(...self::BASE, ...['control'])));
    }

    /**
     * Get registration macros (template) by ID with full management data.
     *
     * @param int $template_id registration macros ID
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_get', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function templateGet(int $template_id): CallToolResult
    {
        return $this->out($this->api->get(WildcoreClient::path(...self::BASE, ...['control', $template_id])));
    }

    /**
     * Create an ONU registration template (Twig) for OLT models. Only one template per model is allowed.
     * Prepare it with ont_registration_template_variables and check it with ont_registration_template_preview.
     * Template variables: ont (the unregistered ONU, e.g. ont._serial_hex), iface (its PON port, e.g. iface._port,
     * iface._technology), free.first / free.all (free ONU IDs on the port), device (device.params - custom
     * parameters), data.<module> (e.g. data.pon_profiles.line / .srv), params, interfaces_list.
     *
     * @param string                     $name        name
     * @param string                     $template    registration commands template
     * @param list<array{id: int}>       $models      OLT models the template is for
     * @param list<array<string, mixed>> $parameters  user input parameters of template
     * @param bool                       $enabled     whether template is active
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_create', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function templateCreate(
        string $name,
        string $template,
        #[Schema(definition: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]], 'description' => 'OLT models, see device_models_list; one registration template per model'])] array $models,
        #[Schema(definition: Macros::PARAMETERS)] array $parameters = [],
        bool $enabled = true,
    ): CallToolResult {
        return $this->out($this->api->post(WildcoreClient::path(...self::BASE, ...['control']), compact(...self::FIELDS)));
    }

    /**
     * Update registration macros (template). Only passed fields are changed.
     *
     * @param int                             $template_id registration macros ID
     * @param string|null                     $name        name
     * @param string|null                     $template    registration commands template
     * @param list<array{id: int}>|null       $models      OLT models the template is for
     * @param list<array<string, mixed>>|null $parameters  user input parameters of template
     * @param bool|null                       $enabled     whether template is active
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_update', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    public function templateUpdate(
        int $template_id,
        ?string $name = null,
        ?string $template = null,
        #[Schema(definition: ['type' => ['array', 'null'], 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]])] ?array $models = null,
        #[Schema(definition: [...Macros::PARAMETERS, 'type' => ['array', 'null']])] ?array $parameters = null,
        ?bool $enabled = null,
    ): CallToolResult {
        $changes = array_filter(compact(...self::FIELDS), static fn ($v) => null !== $v);

        return $this->out($this->api->put(WildcoreClient::path(...self::BASE, ...['control', $template_id]), $changes));
    }

    /**
     * Clone registration macros (template).
     *
     * @param int $template_id registration macros ID
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_clone', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function templateClone(int $template_id): CallToolResult
    {
        return $this->out($this->api->put(WildcoreClient::path(...self::BASE, ...['control', 'clone', $template_id])));
    }

    /**
     * Delete registration macros (template).
     *
     * @param int $template_id registration macros ID
     */
    #[Requires(permissions: ['unregistered_onts_config'])]
    #[McpTool(name: 'ont_registration_template_delete', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))]
    public function templateDelete(int $template_id): CallToolResult
    {
        return $this->out($this->api->delete(WildcoreClient::path(...self::BASE, ...['control', $template_id])));
    }

    /**
     * @param array<string, mixed> $ont
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function body(int $device_id, array $ont, array $params, string $source): array
    {
        return [
            'device' => ['id' => $device_id],
            'ont' => (object) $ont,
            'params' => (object) $params,
            'from' => $this->source($source),
        ];
    }
}
