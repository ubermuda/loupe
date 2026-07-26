<?php

declare(strict_types=1);

namespace App\Module\Account\Event;

use App\Module\Account\Entity\User;

/**
 * Dispatched once a registration transaction has committed — form and social
 * paths alike. The account exists and is flushed; listeners may lazily create
 * satellite state (e.g. Billing provisions the trial here). Listener failures
 * surface to the request but never undo the registration itself.
 */
final readonly class UserRegistered
{
    public function __construct(
        public User $user,
    ) {
    }
}
