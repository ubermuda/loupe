<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\Dev\MintAccessTokenCommand;
use App\Module\OAuth\Command\Dev\MintAccessTokenHandler;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An access token for a test that calls an /api or /mcp endpoint over HTTP.
 *
 * It mints the token rather than running a grant, so a test that is about
 * something else does not pay for three requests and a consent page. What it
 * issues is a token the firewall accepts, and MintAccessTokenHandlerTest proves that
 * over HTTP. The grants themselves are covered under tests/Module/OAuth/.
 */
final readonly class AgentCredential
{
    public static function agentToken(ContainerInterface $container, User $owner): string
    {
        return self::tokenFor($container, $owner, 'agent');
    }

    /** An access token for any scope, bound to $project where the scope needs one. */
    public static function tokenFor(ContainerInterface $container, User $owner, string $scope, ?Project $project = null): string
    {
        $mint = $container->get(MintAccessTokenHandler::class);
        if (!$mint instanceof MintAccessTokenHandler) {
            throw new \LogicException(MintAccessTokenHandler::class.' is missing. It exists in dev and test alone.');
        }

        $scopes = array_values(array_filter(explode(' ', $scope)));

        return $mint(new MintAccessTokenCommand($owner, [] !== $scopes ? $scopes : throw new \LogicException('name at least one scope.'), $project));
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
