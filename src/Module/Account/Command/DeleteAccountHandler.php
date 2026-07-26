<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
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

        // Read the Stripe identifiers before the transaction starts: the
        // cancellation itself must not run inside it (an external API call
        // must never hold a DB transaction open, and Stripe downtime must
        // never block deletion), and by the time BillingProfileAccountPurger
        // deletes the row below there is nothing left to read it from.
        $profile = $this->billingProfiles->findOneByUser($user);
        $subscriptionId = $profile?->stripeSubscriptionId;
        $customerId = $profile?->stripeCustomerId;

        $cleanup = new AccountDeletionCleanup();

        $this->em->wrapInTransaction(function () use ($user, $userId, $cleanup): void {
            foreach ($this->purgers as $purger) {
                $purger->purge($user, $cleanup);
            }

            $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $userId]);
        });

        // Only after a successful commit is it safe to act on anything a
        // purger deferred: a rolled-back transaction must leave on-disk
        // archives, and Stripe, untouched.
        foreach ($cleanup->filesToUnlink() as $path) {
            if (is_file($path) && !@unlink($path)) {
                $this->logger->warning('account.deletion.archive_unlink_failed', ['path' => $path]);
            }
        }

        // Dispatched only now that wrapInTransaction() has returned normally
        // — a rolled-back deletion throws out of the call above and never
        // reaches this line, so it never touches Stripe. The `async`
        // transport's retry_strategy (messenger.yaml) retries the
        // cancellation itself; LogSubscriptionCancelFinalFailure logs a
        // permanent failure once retries are exhausted.
        if (null !== $subscriptionId) {
            $this->bus->dispatch(new CancelSubscriptionMessage($subscriptionId, $customerId, (string) $userId));
        }

        $this->logger->info('account.deleted', ['userId' => (string) $userId]);
    }
}
