<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Module\Account\Entity\User;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Entity\User as LeagueUser;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Stores the user id, not the email that getUserIdentifier() returns, in every
 * token row and in the JWT subject. An email change then keeps the grants, and
 * the account purger finds the rows by id.
 */
#[AsDecorator('league.oauth2_server.converter.user')]
final readonly class UserIdConverter implements UserConverterInterface
{
    #[\Override]
    public function toLeague(UserInterface $user): UserEntityInterface
    {
        if (!$user instanceof User || null === $user->id) {
            throw new \LogicException('An OAuth grant needs a persisted Loupe user.');
        }

        $leagueUser = new LeagueUser();
        $leagueUser->setIdentifier($user->id->toRfc4122());

        return $leagueUser;
    }
}
