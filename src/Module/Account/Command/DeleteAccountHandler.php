<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Validates the deletion token, then hard-deletes the user and every row
 * they own inside one transaction.
 *
 * Each module contributes an AccountDataPurgerInterface (tagged
 * app.account_data_purger, mirroring the UserDataExporterInterface export
 * design) that clears its own tables; this handler orders and iterates them,
 * then deletes the user row itself.
 *
 * Billing is the one module this handler still depends on directly: the Stripe
 * identifiers must be read before BillingProfileAccountPurger deletes the row
 * holding them, so it cannot be reached through the purger alone.
 */
final readonly class DeleteAccountHandler
{
    /** @var list<AccountDataPurgerInterface> */
    private array $purgers;

    /** @param iterable<AccountDataPurgerInterface> $purgers */
    public function __construct(
        private UserRepository $users,
        private BillingProfileRepository $billingProfiles,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,

        #[Target('export.storage')]
        private FilesystemOperator $exportStorage,

        #[AutowireIterator('app.account_data_purger')]
        iterable $purgers,
    ) {
        $ordered = iterator_to_array($purgers, false);
        usort($ordered, static fn (AccountDataPurgerInterface $a, AccountDataPurgerInterface $b): int => $a->deletionOrder() <=> $b->deletionOrder());
        $this->purgers = $ordered;
    }

    public function __invoke(DeleteAccountCommand $command): void
    {
        $user = $this->users->findByAccountDeletionToken($command->token);
        if (!$user instanceof User || !$user->isAccountDeletionTokenValid($command->token)) {
            throw new DomainErrors(['token' => 'account.delete.error.invalid_token']);
        }

        $userId = $user->id ?? throw new \LogicException('a persisted user always has an id');

        // Read the Stripe identifiers before the transaction starts: by the
        // time BillingProfileAccountPurger deletes the row below there is
        // nothing left to read them from.
        $profile = $this->billingProfiles->findOneByUser($user);
        $subscriptionId = $profile?->stripeSubscriptionId;
        $customerId = $profile?->stripeCustomerId;

        $cleanup = new AccountDeletionCleanup();

        $this->em->wrapInTransaction(function () use ($user, $userId, $cleanup, $subscriptionId, $customerId): void {
            foreach ($this->purgers as $purger) {
                $purger->purge($user, $cleanup);
            }

            // Recorded, not called: the async transport is doctrine://default —
            // the same connection as this transaction — so the row commits or
            // rolls back with the deletion. The Stripe call happens later, in
            // CancelSubscriptionHandler, because an external call must never
            // hold a DB transaction open.
            if (null !== $subscriptionId) {
                $this->bus->dispatch(new CancelSubscriptionMessage($subscriptionId, $customerId, (string) $userId));
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
                $this->logger->warning('account.deletion.archive_unlink_failed', ['key' => $key]);
            }
        }

        $this->logger->info('account.deleted', ['userId' => (string) $userId]);
    }
}
