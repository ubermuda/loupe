<?php

declare(strict_types=1);

namespace App\Module\Account\Registration;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class RegistrationPasses
{
    /** The one session key that carries a pass token from a link to either sign-up path. */
    public const string SESSION_KEY = 'registration_pass_token';

    /**
     * @param iterable<RegistrationPassInterface> $passes
     */
    public function __construct(
        #[AutowireIterator('app.registration_pass')]
        private iterable $passes,
    ) {
    }

    public function isValid(string $token): bool
    {
        foreach ($this->passes as $pass) {
            if ($pass->isValid($token)) {
                return true;
            }
        }

        return false;
    }

    public function redeem(string $token, User $user): bool
    {
        foreach ($this->passes as $pass) {
            if ($pass->redeem($token, $user)) {
                return true;
            }
        }

        return false;
    }
}
