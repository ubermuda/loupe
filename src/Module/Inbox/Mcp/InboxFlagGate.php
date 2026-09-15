<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Exception\ToolCallException;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The self-check every inbox tool runs first. The service tag filters
 * tools/list only, so a client with an older tool list can still call a tool.
 */
final readonly class InboxFlagGate
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function requireEnabled(): void
    {
        if (!$this->featureFlags->isEnabled(InboxInstallFlags::FLAG_INBOX_ENABLED)) {
            throw new ToolCallException('The inbox is switched off on this instance.');
        }
    }
}
