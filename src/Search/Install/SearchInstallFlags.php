<?php

declare(strict_types=1);

namespace App\Search\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class SearchInstallFlags implements InstallFlagDefaultsInterface
{
    public const string FLAG_TOPBAR_ENABLED = 'search.topbar.enabled';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(self::FLAG_TOPBAR_ENABLED, FeatureFlagType::Bool, false);
    }
}
