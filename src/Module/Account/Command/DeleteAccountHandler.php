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
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Validates the deletion token, then hard-deletes the user and every row
 * they own inside one transaction.
 *
 * Each module contributes an AccountDataPurgerInterface (tagged
 * app.account_data_purger, mirroring the UserDataExporterInterface export
 * design) that clears its own tables; this handler only orders and iterates
 * them, then deletes the user row itself — it no longer names any other
 * module's table directly.
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

            // Recorded transactionally, not called: MESSENGER_TRANSPORT_DSN
            // is doctrine://default — the SAME DBAL connection as this
            // transaction — and DoctrineTransport::send() is a plain INSERT
            // with no transaction of its own, so this row is written by the
            // same commit as everything else here. A rolled-back deletion
            // rolls this row back too, and the worker consuming the async
            // transport can only ever see it once the whole transaction has
            // durably committed. The actual Stripe API call happens later,
            // outside this transaction, in CancelSubscriptionHandler — an
            // external call must never hold a DB transaction open — and is
            // retried by the `async` transport's retry_strategy
            // (messenger.yaml); LogSubscriptionCancelFinalFailure logs a
            // permanent failure once retries are exhausted.
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
