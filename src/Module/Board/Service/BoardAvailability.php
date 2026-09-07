<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Install\BoardInstallFlags;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Whether the board is switched on for this instance.
 *
 * The flag ships off, so a board route on an instance nobody has told otherwise
 * answers as though the feature had never been deployed.
 */
final readonly class BoardAvailability
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->featureFlags->isEnabled(BoardInstallFlags::FLAG_BOARD_ENABLED);
    }

    public function requireEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new NotFoundHttpException('The board is switched off on this instance.');
        }
    }
}
