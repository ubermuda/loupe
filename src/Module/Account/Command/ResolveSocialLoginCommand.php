<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Service\SocialProfile;

final readonly class ResolveSocialLoginCommand
{
    public function __construct(
        public SocialProfile $profile,
        /** Plain registration-pass token from the session, if any. */
        public ?string $passToken = null,
    ) {
    }
}
