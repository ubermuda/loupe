<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

/**
 * A social identity waiting for password confirmation, together with the id of
 * the account the resolver matched it to. The id is carried explicitly so the
 * confirmation step links the exact account that was matched during the OAuth
 * callback instead of re-running an email lookup that could resolve differently.
 */
final readonly class PendingLink
{
    public function __construct(
        public SocialProfile $profile,
        public string $userId,
    ) {
    }
}
