<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\GrantRepository;
use Symfony\Component\Uid\Uuid;

/** Every OAuth token and authorization code issued to the user. */
final readonly class OAuthGrantAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private GrantRepository $grants,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 45;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->grants->deleteForUser(Uuid::fromString($id));
    }
}
