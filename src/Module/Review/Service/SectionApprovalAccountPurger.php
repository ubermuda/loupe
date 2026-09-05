<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Section approvals the user recorded on documents they do not own. */
final readonly class SectionApprovalAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 55;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->em->getConnection()->executeStatement('DELETE FROM section_approvals WHERE approver_id = :id', ['id' => $id]);
    }
}
