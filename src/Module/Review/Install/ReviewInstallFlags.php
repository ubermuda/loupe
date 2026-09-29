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
    public const string FLAG_MERMAID = 'review.mermaid.enabled';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: agent-placed highlights steer where a reviewer looks first, which
        // is a nudge an operator opts into rather than inherits.
        yield new InstallFlagDefault(DocumentHighlightTool::FLAG, FeatureFlagType::Bool, false);
        // Off: diagrams make the reader's browser call a third-party CDN, which an operator opts into.
        yield new InstallFlagDefault(self::FLAG_MERMAID, FeatureFlagType::Bool, false);
    }
}
