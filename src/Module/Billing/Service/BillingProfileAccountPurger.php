<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes the user's billing profile row and asks for the Stripe subscription
 * behind it to be cancelled.
 *
 * The identifiers are read here, immediately before the delete that destroys
 * them, so Account does not need to reach into Billing to fetch them first. The
 * cancellation itself is only recorded: the Stripe call happens later in
 * CancelSubscriptionHandler, because an external call must never hold a
 * database transaction open.
 */
final readonly class BillingProfileAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 80;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $connection = $this->em->getConnection();
        $profile = $connection->fetchAssociative(
            'SELECT stripe_subscription_id, stripe_customer_id FROM billing_profiles WHERE user_id = :id', // @translation-check-ignore
            ['id' => $id],
        );

        $connection->executeStatement('DELETE FROM billing_profiles WHERE user_id = :id', ['id' => $id]);

        $subscriptionId = false === $profile ? null : $profile['stripe_subscription_id'];
        if (is_string($subscriptionId) && '' !== $subscriptionId) {
            $customerId = $profile['stripe_customer_id'] ?? null;
            $cleanup->scheduleMessage(new CancelSubscriptionMessage(
                $subscriptionId,
                is_string($customerId) ? $customerId : null,
                $id,
            ));
        }
    }
}
