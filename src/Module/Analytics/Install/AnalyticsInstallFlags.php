<?php

declare(strict_types=1);

namespace App\Module\Analytics\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Analytics\Twig\AnalyticsScript;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class AnalyticsInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: an instance sends nothing to a third party until someone decides
        // it should, and self-hosted installs inherit this default.
        yield new InstallFlagDefault(AnalyticsScript::ENABLED_FLAG, FeatureFlagType::Bool, false);
    }
}
