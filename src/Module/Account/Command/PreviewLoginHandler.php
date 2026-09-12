<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * SECURITY: this grants a session for a seeded account. #[When('dev')] is what
 * keeps the service out of production; the signature that authorises the
 * request belongs to the route and is checked before this runs.
 */
#[When('dev')]
final readonly class PreviewLoginHandler
{
    /** @param UserProviderInterface<User> $userProvider */
    public function __construct(
        private UserProviderInterface $userProvider,
        private Security $security,
    ) {
    }

    public function __invoke(PreviewLoginCommand $command): ?User
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($command->email);
        } catch (UserNotFoundException) {
            return null;
        }

        $this->security->login($user, 'form_login');

        return $user;
    }
}
