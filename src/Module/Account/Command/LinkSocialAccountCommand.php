<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Service\SocialProfile;

final readonly class LinkSocialAccountCommand
{
    public function __construct(
        /** Id of the account the OAuth callback matched — never re-derived from the email. */
        public string $userId,
        public SocialProfile $profile,
        public string $plainPassword,
    ) {
    }
}
