<?php

declare(strict_types=1);

namespace WildcoreMcp;

/**
 * What a tool needs in WildCore to be listed, same idea as need_permissions/need_components
 * of the web panel menu. Set on a tool class (applies to all its tools) or on a method.
 * Components of class and method are combined, method permissions replace class ones.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Requires
{
    /**
     * @param list<string> $permissions WildCore role permissions, any of them is enough
     * @param list<string> $components  WildCore components that must be enabled
     * @param bool         $rootOnly    only for root users (system/owner roles) - routes without permission rules
     * @param bool         $secret      returns credentials (configs, passwords) - listed only with ENABLE_SECRETS=true
     */
    public function __construct(
        public readonly array $permissions = [],
        public readonly array $components = [],
        public readonly bool $rootOnly = false,
        public readonly bool $secret = false,
    ) {
    }
}
