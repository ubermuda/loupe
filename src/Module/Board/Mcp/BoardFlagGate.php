<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Install\BoardInstallFlags;
use Mcp\Exception\ToolCallException;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The self-check every board tool runs first.
 *
 * The service tag filters tools/list only, so a client replaying an older tool
 * list can still call a tool the flag has since hidden.
 */
final readonly class BoardFlagGate
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function requireEnabled(): void
    {
        if (!$this->featureFlags->isEnabled(BoardInstallFlags::FLAG_BOARD_ENABLED)) {
            throw new ToolCallException('The board is switched off on this instance.');
        }
    }
}
