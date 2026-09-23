<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\InvalidGitHubDelivery;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class RefusedDeliveries
{
    public function __construct(
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, string> $context what the route itself vouches for, never the body */
    public function record(InvalidGitHubDelivery $refusal, array $context = []): void
    {
        // No subject and no context: nothing in the body is trustworthy,
        // including the repository it names.
        $this->auditor->record(
            'forge.delivery_rejected',
            AuditOutcome::Refused,
            category: Auditor::CATEGORY_SECURITY,
        );

        $this->logger->warning('forge.delivery_rejected', ['forge' => 'github', 'reason' => $refusal->reason, ...$context]);
    }
}
