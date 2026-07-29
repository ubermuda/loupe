<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

/** What MarkEmailVerifiedHandler had to change to reach "verified, with no outstanding link". */
final readonly class MarkEmailVerifiedResult
{
    public function __construct(
        /** False when the email was already verified, which leaves the timestamp untouched. */
        public bool $verified,
        /** True when a pending verification link was revoked — surprising enough to report. */
        public bool $tokenRevoked,
    ) {
    }
}
