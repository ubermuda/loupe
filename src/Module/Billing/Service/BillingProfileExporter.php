<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Repository\BillingProfileRepository;

final readonly class BillingProfileExporter implements UserDataExporterInterface
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'billing_profile.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $profile = $this->billingProfiles->findOneByUser($user);
        if (null === $profile) {
            return;
        }

        yield 'stripeCustomerId' => $profile->stripeCustomerId;
        yield 'createdAt' => $profile->createdAt->format(\DateTimeInterface::ATOM);
        yield 'subscriptions' => array_values(array_map(
            static fn (Subscription $subscription): array => [
                'kind' => $subscription->kind->value,
                'startsAt' => $subscription->startsAt->format(\DateTimeInterface::ATOM),
                'endsAt' => $subscription->endsAt?->format(\DateTimeInterface::ATOM),
                'stripeSubscriptionId' => $subscription->stripeSubscriptionId,
                'stripeStatus' => $subscription->stripeStatus?->value,
            ],
            $profile->subscriptions->toArray(),
        ));
    }
}
