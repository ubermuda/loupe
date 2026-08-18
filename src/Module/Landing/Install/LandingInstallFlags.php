<?php

declare(strict_types=1);

namespace App\Module\Landing\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Landing\Controller\LandingController;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class LandingInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: the marketing page advertises a hosted plan with a price on it,
        // which only the instance selling that plan should serve.
        yield new InstallFlagDefault(LandingController::ENABLED_FLAG, FeatureFlagType::Bool, false);
    }
}
