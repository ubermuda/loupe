<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\SiteReview\SiteReviewDrawing;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class SiteReviewInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Seeded to the same value every call site passes as its default, so a
        // fresh install and an instance that never ran the seeder agree.
        yield new InstallFlagDefault(SiteReviewDrawing::FLAG, FeatureFlagType::Bool, SiteReviewDrawing::DEFAULT);
    }
}
