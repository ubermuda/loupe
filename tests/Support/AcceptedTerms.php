<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks a fixture user as having accepted the current terms, because almost
 * every WebTestCase stands its user in for an established account rather than
 * one RequireTermsAcceptanceListener should divert. The gate's own tests build
 * their users without this.
 */
final readonly class AcceptedTerms
{
    public static function stamp(User $user, ContainerInterface $container): User
    {
        $version = $container->getParameter('app.terms.version');
        if (!is_string($version)) {
            throw new \LogicException('app.terms.version must be a string.');
        }

        $user->termsAcceptedAt = new \DateTimeImmutable();
        $user->termsVersion = $version;

        return $user;
    }
}
