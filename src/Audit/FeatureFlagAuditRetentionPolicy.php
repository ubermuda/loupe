<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\AuditBundle\AuditRetentionPolicyInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The retention window as an admin-editable flag, so an operator running the
 * published image can change it without a rebuild.
 *
 * It lives on this side of the boundary because the audit package must not
 * import the flags bundle. The container parameter stays the answer for an
 * instance whose flag row was never seeded, which is every instance installed
 * before the flag existed.
 */
final readonly class FeatureFlagAuditRetentionPolicy implements AuditRetentionPolicyInterface
{
    public const string FLAG = 'audit.retention_days';

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'ubermuda_audit.retention_days')]
        private int $default,
    ) {
    }

    #[\Override]
    public function retentionDays(): int
    {
        // The flag is operator-typed, and a window of zero deletes the whole trail.
        return max(1, $this->featureFlags->getIntValue(self::FLAG, $this->default));
    }
}
