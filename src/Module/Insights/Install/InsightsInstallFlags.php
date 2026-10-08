<?php

declare(strict_types=1);

namespace App\Module\Insights\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Insights\Service\AnalysisSettings;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class InsightsInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(AnalysisSettings::MODEL_FLAG, FeatureFlagType::String, AnalysisSettings::DEFAULT_MODEL);
        yield new InstallFlagDefault(AnalysisSettings::EFFORT_FLAG, FeatureFlagType::String, AnalysisSettings::DEFAULT_EFFORT);
    }
}
