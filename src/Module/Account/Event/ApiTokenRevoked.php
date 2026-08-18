<?php

declare(strict_types=1);

namespace App\Module\Account\Event;

use App\Module\Account\Entity\ApiToken;

/**
 * Dispatched inside RevokeApiTokenHandler's flush, once the token is marked
 * revoked. Revocation keeps the row, so no ON DELETE cascade fires and modules
 * holding a reference to the token clear their own in a listener — the event
 * class is Account's public API for it.
 */
final readonly class ApiTokenRevoked
{
    public function __construct(
        public ApiToken $token,
    ) {
    }
}
