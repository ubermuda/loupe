<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class SeedInstallFlagsCommand
{
    public function __construct(
        public int $registrationCap,
        public bool $registrationEnabled,
        public bool $billingEnabled,
        public int $billingTrialDays,
        public ?string $billingStripePriceId,
        public bool $authGithubEnabled,
        public bool $authGoogleEnabled,
    ) {
    }
}
