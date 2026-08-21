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
    public function export(User $user): array
    {
        $profile = $this->billingProfiles->findOneByUser($user);
        if (null === $profile) {
            return [];
        }

        return [
            'status' => $profile->status->value,
            'stripeCustomerId' => $profile->stripeCustomerId,
            'stripeSubscriptionId' => $profile->stripeSubscriptionId,
            'trialEndsAt' => $profile->trialEndsAt->format(\DateTimeInterface::ATOM),
            'currentPeriodEnd' => $profile->currentPeriodEnd?->format(\DateTimeInterface::ATOM),
            'createdAt' => $profile->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
