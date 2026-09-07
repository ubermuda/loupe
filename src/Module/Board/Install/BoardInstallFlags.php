<?php

declare(strict_types=1);

namespace App\Module\Board\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class BoardInstallFlags implements InstallFlagDefaultsInterface
{
    public const string FLAG_BOARD_ENABLED = 'board.enabled';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: the operator opts in, because a board an agent writes to is a
        // second place work is tracked rather than an addition to the first.
        yield new InstallFlagDefault(self::FLAG_BOARD_ENABLED, FeatureFlagType::Bool, false);
    }
}
