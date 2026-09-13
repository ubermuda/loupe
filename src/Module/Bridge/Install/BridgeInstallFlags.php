<?php

declare(strict_types=1);

namespace App\Module\Bridge\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * Seeds the retention window from the container parameter, so the flag row and
 * the coded fallback start out saying the same number.
 */
final readonly class BridgeInstallFlags implements InstallFlagDefaultsInterface
{
    public function __construct(
        #[Autowire(param: 'app.bridge.run_retention_days')]
        private int $retentionDays,
    ) {
    }

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Not a wizard question: how long to keep run history is a decision an
        // operator makes later, in the admin area.
        yield new InstallFlagDefault(WorkerRunRetentionPolicy::FLAG, FeatureFlagType::Int, $this->retentionDays);
    }
}
