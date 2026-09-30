<?php

declare(strict_types=1);

namespace App\Module\Account\Registration;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A token that lets one sign-up past a full registration cap.
 */
#[AutoconfigureTag('app.registration_pass')]
interface RegistrationPassInterface
{
    /** Reads without a lock, so a later redeem() can still refuse the token. */
    public function isValid(string $token): bool;

    /**
     * Locks the pass, checks it again and marks it redeemed by $user. Called
     * inside the capacity-lock transaction, before $user is persisted.
     */
    public function redeem(string $token, User $user): bool;
}
