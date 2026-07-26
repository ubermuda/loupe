<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Reviews the user submitted on documents they do not own. */
final readonly class ReviewAuthorshipAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 60;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->em->getConnection()->executeStatement('DELETE FROM reviews WHERE reviewer_id = :id', ['id' => $id]);
    }
}
