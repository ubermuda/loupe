<?php

declare(strict_types=1);

namespace App\Module\Account\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Service\RegistrationGate;
use App\Service\UpdateCheck;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** Registration and social sign-in, both answered by the install form. */
final readonly class AccountInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(RegistrationGate::CAP_FLAG, FeatureFlagType::Int, $command->registrationCap);
        yield new InstallFlagDefault(RegistrationGate::ENABLED_FLAG, FeatureFlagType::Bool, $command->registrationEnabled);
        yield new InstallFlagDefault('auth.github.enabled', FeatureFlagType::Bool, $command->authGithubEnabled);
        yield new InstallFlagDefault('auth.google.enabled', FeatureFlagType::Bool, $command->authGoogleEnabled);

        // Off: it is the only outbound request the app makes on its own, and an
        // operator has to choose to tell GitHub this instance exists. Declared
        // here because UpdateCheck is a root service with no module to own it.
        yield new InstallFlagDefault(UpdateCheck::FLAG, FeatureFlagType::Bool, false);
    }
}
