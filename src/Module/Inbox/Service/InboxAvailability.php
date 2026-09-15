<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Install\InboxInstallFlags;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Whether the inbox is switched on. The flag ships off, so an inbox route answers as though it was never deployed. */
final readonly class InboxAvailability
{
    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->featureFlags->isEnabled(InboxInstallFlags::FLAG_INBOX_ENABLED);
    }

    public function requireEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new NotFoundHttpException('The inbox is switched off on this instance.');
        }
    }
}
