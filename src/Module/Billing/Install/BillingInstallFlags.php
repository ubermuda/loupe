<?php

declare(strict_types=1);

namespace App\Module\Billing\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** All three answered by the install form's billing step. */
final readonly class BillingInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault('billing.enabled', FeatureFlagType::Bool, $command->billingEnabled);
        yield new InstallFlagDefault('billing.trial_days', FeatureFlagType::Int, $command->billingTrialDays);
        yield new InstallFlagDefault('billing.stripe_price_id', FeatureFlagType::Select, $command->billingStripePriceId, []);
    }
}
