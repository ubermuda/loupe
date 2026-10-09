<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use Symfony\Component\Uid\Uuid;

/** The stored GitHub tokens of the user. GitHub keeps its grant until the person removes it there. */
final readonly class GitHubUserConnectionAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private GitHubUserConnectionRepository $gitHubUserConnections,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 47;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->gitHubUserConnections->deleteForUser(Uuid::fromString($id));
    }
}
