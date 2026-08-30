<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountDeletionPreparerInterface;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the departed account from the audit trail. It lives on this side of
 * the boundary because the audit package cannot know about account deletion.
 *
 * Records of what the account did go, and records about it stay whole. A record
 * about the account names it as a subject type and an id, never as a name, and
 * carries the acting party's name instead — so it holds nothing of the
 * departed account to scrub, and nulling its label would erase an admin's name
 * from exactly the records a later reader came for.
 */
final readonly class AuditLogAccountPurger implements AccountDataPurgerInterface, AccountDeletionPreparerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * A record made with the account's API token names no actor of its own, so
     * only the credential identifies it. audit_log.credential_id is ON DELETE
     * SET NULL, and ProjectAccountPurger deletes each project's bound tokens at
     * the first slot, so the link is gone before any purger runs. This is the
     * last moment it still resolves.
     */
    #[\Override]
    public function prepare(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $this->em->getConnection()->executeStatement(
            'DELETE FROM audit_log WHERE credential_id IN (SELECT id FROM api_tokens WHERE owner_id = :id)',
            ['id' => $id],
        );
    }

    /** No ordering constraint of its own: the credential work happens in prepare(). */
    #[\Override]
    public function deletionOrder(): int
    {
        return 35;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        // ProjectAccountPurger runs first and calls EntityManager::clear(), so
        // $user may be detached: read both keys as scalars up front, and never
        // query by the object.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $label = $user->auditLabel();

        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM audit_log WHERE actor_id = :id', ['id' => $id]);

        if (null !== $label) {
            // The label and the id are written independently, so a record can
            // carry the name with no id and outlive the delete above. A name is
            // not unique: two accounts sharing one lose each other's
            // unattributable records. Over-deletion, and there is nothing
            // narrower to key on.
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE actor_id IS NULL AND actor_label = :label',
                ['label' => $label],
            );
        }
    }
}
