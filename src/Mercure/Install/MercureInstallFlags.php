<?php

declare(strict_types=1);

namespace App\Mercure\Install;

use App\Mercure\LiveUpdates;
use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class MercureInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // On, like agent push: the environment prerequisite holds it off until
        // a hub is configured.
        yield new InstallFlagDefault(LiveUpdates::FLAG, FeatureFlagType::Bool, true);
    }
}
