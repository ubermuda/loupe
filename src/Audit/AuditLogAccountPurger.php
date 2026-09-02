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
 *
 * The data export hands the user those same subject records, which reads as the
 * opposite rule and is not one. Access and erasure are separate rights, so a
 * record of what was done to an account is both readable by it and allowed to
 * outlive it.
 */
final readonly class AuditLogAccountPurger implements AccountDataPurgerInterface, AccountDeletionPreparerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Defensive, and deliberately kept. ApiTokenAuthenticator builds its
     * passport from the token's owner, so every token-authenticated record
     * carries that owner as its actor and purge() already removes it. Nothing
     * writes a credential-only row today.
     *
     * It runs here rather than at slot 35 because the link would be gone by
     * then: audit_log.credential_id is ON DELETE SET NULL, and
     * ProjectAccountPurger deletes each project's bound tokens at the first
     * slot. This is the last moment a credential still resolves, so a provider
     * that did leave the actor null would still be covered.
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
        // $user may be detached: read the key as a scalar up front, and never
        // query by the object.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        // Do not also match actor_label. A display name is not unique and it
        // changes, so the match takes a namesake's records and misses the
        // account's own records written under an older name.
        $this->em->getConnection()->executeStatement(
            'DELETE FROM audit_log WHERE actor_id = :id',
            ['id' => $id],
        );
    }
}
