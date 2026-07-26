<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Comments the user authored on documents they do NOT own — e.g. as an
 * invited reviewer on someone else's project. comments.parent_id has no ON
 * DELETE clause, so any live descendant reply (by any author) must be
 * removed first. Walk downward from the user's own comments, collecting the
 * full descendant closure level by level, then delete deepest level first so
 * a row is never removed while something still points at it as a parent.
 */
final readonly class CommentAuthorshipAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 50;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $conn = $this->em->getConnection();
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $levels = [];
        $frontier = array_map(strval(...), $conn->fetchFirstColumn('SELECT id FROM comments WHERE author_id = :id', ['id' => $id]));
        while ([] !== $frontier) {
            $levels[] = $frontier;
            $frontier = array_map(strval(...), $conn->fetchFirstColumn(
                'SELECT id FROM comments WHERE parent_id IN (:ids)',
                ['ids' => $frontier],
                ['ids' => ArrayParameterType::STRING],
            ));
        }
        foreach (array_reverse($levels) as $level) {
            $conn->executeStatement(
                'DELETE FROM comments WHERE id IN (:ids)',
                ['ids' => $level],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }
}
