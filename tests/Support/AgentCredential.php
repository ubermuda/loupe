<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An access token with the agent scope, for a test that calls an /api endpoint
 * over HTTP. It runs the real authorization code flow, so the token the test
 * sends is the one the firewall would accept in production.
 *
 * The browser is signed out again at the end, because the flow signs the user
 * in to reach the consent page and an API test must carry the bearer alone.
 *
 * A signed-in request clears the entity manager, so every entity the test held
 * before this call is detached afterwards. Pass one back through managed() to
 * persist anything that points at it.
 */
final readonly class AgentCredential
{
    public static function agentToken(ContainerInterface $container, KernelBrowser $browser, User $owner): string
    {
        return self::tokenFor($container, $browser, $owner, 'agent');
    }

    /** An access token for any scope, bound to $project where the scope needs one. */
    public static function tokenFor(ContainerInterface $container, KernelBrowser $browser, User $owner, string $scope, ?Project $project = null): string
    {
        $scenario = new OAuthScenario($container);
        $scenario->createClient();

        return $scenario->accessTokenFor($browser, $owner, $scope, $project);
    }

    /**
     * The managed instance of an entity, which is the entity itself until a
     * request detached it.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public static function managed(EntityManagerInterface $em, object $entity, string|Uuid|null $id): object
    {
        if ($em->contains($entity)) {
            return $entity;
        }

        $found = $em->find($entity::class, (string) $id);

        return $found instanceof $entity ? $found : throw new \LogicException($entity::class.' '.$id.' is gone from the database.');
    }
}
