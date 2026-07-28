<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class InstallFlagsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $registrationCap = 0,
        /**
         * Default true, and it has to stay that way: this is the value the
         * wizard seeds, so an unchecked default would leave every freshly
         * installed instance unable to register anyone — including the e2e
         * suite, whose install spec runs last and leaves its seeded flags as
         * the next run's starting state.
         */
        public bool $registrationEnabled = true,
        public bool $billingEnabled = false,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $billingTrialDays = 14,

        #[Assert\Length(max: 255)]
        public ?string $billingStripePriceId = null,
        public bool $authGithubEnabled = false,
        public bool $authGoogleEnabled = false,
    ) {
    }
}
