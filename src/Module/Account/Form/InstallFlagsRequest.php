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
