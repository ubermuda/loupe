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
        // Seeded on rather than off. Its environment prerequisite already holds
        // it off on an instance with no hub configured, so seeding it off would
        // mean an operator who *did* configure Mercure still had to find a
        // switch to make it work.
        yield new InstallFlagDefault('site_review.push.enabled', FeatureFlagType::Bool, true);

        // Seeded to the same value every call site passes as its default, so a
        // fresh install and an instance that never ran the seeder agree.
        yield new InstallFlagDefault(SiteReviewDrawing::FLAG, FeatureFlagType::Bool, SiteReviewDrawing::DEFAULT);
    }
}
