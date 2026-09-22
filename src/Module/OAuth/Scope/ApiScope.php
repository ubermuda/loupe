<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scope;

enum ApiScope: string
{
    case Mcp = 'mcp';
    case SiteReview = 'site-review';
    case Agent = 'agent';

    public function role(): string
    {
        return match ($this) {
            self::Mcp => 'ROLE_API_MCP',
            self::SiteReview => 'ROLE_API_SITE_REVIEW',
            self::Agent => 'ROLE_API_AGENT',
        };
    }
}
