<?php

declare(strict_types=1);

namespace WildcoreMcp\Tools\Core;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use WildcoreMcp\Requires;
use WildcoreMcp\Tools\AbstractTools;

/**
 * Users and roles.
 */
final class Users extends AbstractTools
{
    public const GROUP = 'users';

    /**
     * List user roles: ID and name. Role IDs are needed to allow a macro to roles (macros_create user_roles).
     */
    #[Requires(permissions: ['user_self_control', 'user_display', 'user_management'])]
    #[McpTool(name: 'user_roles_list', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function roles(): CallToolResult
    {
        $roles = $this->api->get('user-role');

        return $this->out(array_map(static fn ($r) => \is_array($r) ? ['id' => $r['id'] ?? null, 'name' => $r['name'] ?? null] : $r, \is_array($roles) ? array_values($roles) : []));
    }
}
