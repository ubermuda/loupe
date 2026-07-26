<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every API token the user owns. ProjectAccountPurger (which runs first)
 * only clears the two tokens reachable from a project's widget/mcp bindings
 * — api_tokens.owner_id is NOT NULL and needs its own sweep for any token
 * not bound to a project.
 */
final readonly class ApiTokenAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 40;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->em->getConnection()->executeStatement('DELETE FROM api_tokens WHERE owner_id = :id', ['id' => $id]);
    }
}
