<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

enum ApiTokenScope: string
{
    case Mcp = 'mcp';
    case SiteReview = 'site-review';

    public function role(): string
    {
        return match ($this) {
            self::Mcp => 'ROLE_API_MCP',
            self::SiteReview => 'ROLE_API_SITE_REVIEW',
        };
    }
}
