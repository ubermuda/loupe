<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;

final readonly class SocialProfile
{
    public function __construct(
        public SocialProvider $provider,
        public string $providerUserId,
        /**
         * The RAW email the provider asserted, for storage/reference only. Whether
         * it may be trusted for matching/linking is carried by $emailVerified — a
         * raw email is NEVER used to match an existing account unless verified.
         */
        public ?string $email,
        public ?string $fullName,
        /**
         * True only when the provider asserted this email is verified (Google's
         * email_verified claim, GitHub's primary+verified /user/emails entry).
         * Required — every construction site must decide explicitly, because
         * trusting an unverified provider email for account matching is an
         * account-takeover vector (nOAuth).
         */
        public bool $emailVerified,
    ) {
    }
}
