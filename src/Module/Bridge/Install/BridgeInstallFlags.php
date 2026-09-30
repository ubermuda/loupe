<?php

declare(strict_types=1);

namespace App\Module\Bridge\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\Service\HeartbeatInterval;
use App\Module\Bridge\Service\WorkerRunRetentionPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * Seeds the retention window, the heartbeat interval and the command lifetime
 * from their container parameters, so each flag row and its coded fallback
 * start out equal.
 */
final readonly class BridgeInstallFlags implements InstallFlagDefaultsInterface
{
    public function __construct(
        #[Autowire(param: 'app.bridge.default_run_retention_days')]
        private int $retentionDays,

        #[Autowire(param: 'app.bridge.default_heartbeat_interval_seconds')]
        private int $heartbeatIntervalSeconds,

        #[Autowire(param: 'app.bridge.default_command_ttl_minutes')]
        private int $commandTtlMinutes,
    ) {
    }

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Not wizard questions: an operator sets these later, in the admin area.
        yield new InstallFlagDefault(WorkerRunRetentionPolicy::FLAG, FeatureFlagType::Int, $this->retentionDays);
        yield new InstallFlagDefault(HeartbeatInterval::FLAG, FeatureFlagType::Int, $this->heartbeatIntervalSeconds);
        yield new InstallFlagDefault(BridgeCommandTtl::FLAG, FeatureFlagType::Int, $this->commandTtlMinutes);
    }
}
