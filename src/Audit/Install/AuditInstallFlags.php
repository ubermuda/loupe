<?php

declare(strict_types=1);

namespace App\Audit\Install;

use App\Audit\FeatureFlagAuditRetentionPolicy;
use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * Seeds the retention window from the container parameter, so the flag row and
 * the coded fallback start out saying the same number.
 */
final readonly class AuditInstallFlags implements InstallFlagDefaultsInterface
{
    public function __construct(
        #[Autowire(param: 'app.audit.retention_days')]
        private int $retentionDays,
    ) {
    }

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Not a wizard question: how long to keep a trail is a decision an
        // operator makes against their own policy, later, in the admin area.
        yield new InstallFlagDefault(FeatureFlagAuditRetentionPolicy::FLAG, FeatureFlagType::Int, $this->retentionDays);
    }
}
