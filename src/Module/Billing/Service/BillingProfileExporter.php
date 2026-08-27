<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
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

        yield 'status' => $profile->status->value;
        yield 'stripeCustomerId' => $profile->stripeCustomerId;
        yield 'stripeSubscriptionId' => $profile->stripeSubscriptionId;
        yield 'trialEndsAt' => $profile->trialEndsAt->format(\DateTimeInterface::ATOM);
        yield 'currentPeriodEnd' => $profile->currentPeriodEnd?->format(\DateTimeInterface::ATOM);
        yield 'createdAt' => $profile->createdAt->format(\DateTimeInterface::ATOM);
    }
}
