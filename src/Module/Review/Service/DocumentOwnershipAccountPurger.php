<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Documents the user owns inside OTHER owners' projects. The schema permits
 * document.owner to differ from project.owner even though no code path
 * creates that today — documents.owner_id is NOT NULL, so a latent such
 * document would otherwise block the user delete.
 *
 * Same FK-safe order as ProjectDeleter's own document-subtree cleanup
 * (reviews, comments, decision selections, versions, then the document), keyed
 * on document ownership instead of the project.
 *
 * The two cleanup paths use different mechanisms — this one raw SQL, the
 * listener DQL bulk deletes — and DQL bulk delete bypasses orphanRemoval, so
 * neither an entity-level cascade nor the other path covers this one. A table
 * added to one must be added to both.
 */
final readonly class DocumentOwnershipAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 30;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $conn = $this->em->getConnection();
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $conn->executeStatement(
            'DELETE FROM reviews WHERE version_id IN (SELECT v.id FROM document_versions v JOIN documents d ON v.document_id = d.id WHERE d.owner_id = :id)',
            ['id' => $id],
        );
        $conn->executeStatement(
            'DELETE FROM comments WHERE version_id IN (SELECT v.id FROM document_versions v JOIN documents d ON v.document_id = d.id WHERE d.owner_id = :id)',
            ['id' => $id],
        );
        $conn->executeStatement(
            'DELETE FROM decision_selections WHERE document_id IN (SELECT id FROM documents WHERE owner_id = :id)',
            ['id' => $id],
        );
        $conn->executeStatement(
            'DELETE FROM document_versions WHERE document_id IN (SELECT id FROM documents WHERE owner_id = :id)',
            ['id' => $id],
        );
        $conn->executeStatement('DELETE FROM documents WHERE owner_id = :id', ['id' => $id]);
    }
}
