<?php

declare(strict_types=1);

namespace App\Module\Review\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Review\Mcp\DocumentHighlightTool;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class ReviewInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: agent-placed highlights steer where a reviewer looks first, which
        // is a nudge an operator opts into rather than inherits.
        yield new InstallFlagDefault(DocumentHighlightTool::FLAG, FeatureFlagType::Bool, false);
    }
}
