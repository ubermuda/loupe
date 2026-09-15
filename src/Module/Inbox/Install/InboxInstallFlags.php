<?php

declare(strict_types=1);

namespace App\Module\Inbox\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class InboxInstallFlags implements InstallFlagDefaultsInterface
{
    public const string FLAG_INBOX_ENABLED = 'inbox.enabled';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: the operator opts in to agents that hand questions to a person.
        yield new InstallFlagDefault(self::FLAG_INBOX_ENABLED, FeatureFlagType::Bool, false);
    }
}
