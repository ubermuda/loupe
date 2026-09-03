<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

use App\Audit\AuditContext;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hard-deletes a user and every row they own inside one transaction, for every
 * caller that has already established the deletion is authorised.
 *
 * Each module contributes an AccountDataPurgerInterface (tagged
 * app.account_data_purger, mirroring the UserDataExporterInterface export
 * design) that clears its own tables; this service orders and iterates them,
 * then deletes the user row itself.
 *
 * A purger that needs follow-up work records it on AccountDeletionCleanup:
 * messages go out inside the transaction, storage deletions after it commits.
 * That is how Billing gets its Stripe cancellation dispatched without this
 * service importing anything from Billing.
 */
final readonly class AccountPurger
{
    /** @var list<AccountDataPurgerInterface> */
    private array $purgers;

    /** @var list<AccountDeletionPreparerInterface> */
    private array $preparers;

    /**
     * @param iterable<AccountDataPurgerInterface>       $purgers
     * @param iterable<AccountDeletionPreparerInterface> $preparers
     */
    public function __construct(
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private AuditContext $auditContext,

        #[Target('export.storage')]
        private FilesystemOperator $exportStorage,

        #[AutowireIterator('app.account_data_purger')]
        iterable $purgers,

        #[AutowireIterator('app.account_deletion_preparer')]
        iterable $preparers,
    ) {
        $ordered = iterator_to_array($purgers, false);
        usort($ordered, static fn (AccountDataPurgerInterface $a, AccountDataPurgerInterface $b): int => $a->deletionOrder() <=> $b->deletionOrder());
        $this->purgers = $ordered;
        $this->preparers = iterator_to_array($preparers, false);
    }

    public function purge(User $user): void
    {
        $userId = $user->id ?? throw new \LogicException('a persisted user always has an id');

        $cleanup = new AccountDeletionCleanup();

        // Before the purgers run, because ProjectAccountPurger records a
        // project deletion of its own at the first slot.
        $this->auditContext->erasedActorId = (string) $userId;

        $this->em->wrapInTransaction(function () use ($user, $userId, $cleanup): void {
            // Before the purgers, not among them: a preparer exists for the row
            // whose owner stops being identifiable once ProjectAccountPurger has
            // run, and that purger has to keep the lowest deletionOrder().
            foreach ($this->preparers as $preparer) {
                $preparer->prepare($user, $cleanup);
            }

            foreach ($this->purgers as $purger) {
                $purger->purge($user, $cleanup);
            }

            // Dispatched here, not after the commit: the async transport is
            // doctrine://default — the same connection as this transaction — so
            // each row commits or rolls back with the deletion itself.
            foreach ($cleanup->messagesToDispatch() as $message) {
                $this->bus->dispatch($message);
            }

            $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $userId]);
        });

        // Only after a successful commit is it safe to act on anything a
        // purger deferred: a rolled-back transaction must leave stored
        // archives untouched.
        foreach ($cleanup->archivesToDelete() as $key) {
            try {
                $this->exportStorage->delete($key);
            } catch (FilesystemException) {
                // The key names the orphaned object and is a storage path, so
                // it stays out: the record says an archive outlived the account
                // and points at the account it belonged to.
                $this->auditor->record(
                    'account.deletion_archive_unlink_failed',
                    AuditOutcome::Failed,
                    ['userId' => (string) $userId],
                    new AuditSubject('user', (string) $userId),
                );
            }
        }

        $this->auditor->record(
            'account.deleted',
            AuditOutcome::Success,
            ['userId' => (string) $userId],
            new AuditSubject('user', (string) $userId),
        );
    }
}
