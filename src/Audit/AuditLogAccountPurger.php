<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the departed account from the audit trail. It lives on this side of
 * the boundary because the audit package cannot know about account deletion.
 *
 * Records of what the user did go. Records about the user stay, because the
 * trail of an action taken on an account is the account's own evidence that it
 * happened; only the stored label goes, which is the one place a name survives
 * a row whose actor association is set to null rather than deleted.
 */
final readonly class AuditLogAccountPurger implements AccountDataPurgerInterface
{
    /** How the trail names a user as the thing an event happened to. */
    public const string USER_SUBJECT_TYPE = 'user';

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /** Before ApiTokenAccountPurger at 40, so a record still names the credential it was made with. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 35;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        // ProjectAccountPurger runs first and calls EntityManager::clear(), so
        // $user may be detached: read the id as a scalar, never query by object.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM audit_log WHERE actor_id = :id', ['id' => $id]);
        $connection->executeStatement(
            'UPDATE audit_log SET actor_label = NULL WHERE subject_type = :type AND subject_id = :id',
            ['type' => self::USER_SUBJECT_TYPE, 'id' => $id],
        );
    }
}
