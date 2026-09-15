<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * How long a run row is kept, as an admin-editable flag, so an operator running
 * the published image can change the window without a rebuild.
 *
 * The container parameter stays the answer for an instance whose flag row was
 * never seeded, which is every instance installed before the flag existed.
 */
final readonly class WorkerRunRetentionPolicy
{
    public const string FLAG = 'bridge.run_retention_days';

    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.bridge.default_run_retention_days')]
        private int $default,
    ) {
    }

    public function retentionDays(): int
    {
        // The flag is operator-typed, and a window of zero deletes every run.
        return max(1, $this->featureFlags->getIntValue(self::FLAG, $this->default));
    }
}
