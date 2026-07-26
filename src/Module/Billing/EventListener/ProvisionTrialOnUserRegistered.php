<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Service\TrialProvisioner;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Anchors the trial clock to registration: every new account gets its
 * BillingProfile (and so its trialEndsAt) immediately, instead of on first
 * paywalled request. PaywallGate keeps its own ensureProfile() call as the
 * safety net for accounts that predate this listener or raced past it.
 */
#[AsEventListener]
final readonly class ProvisionTrialOnUserRegistered
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        $this->trialProvisioner->ensureProfile($event->user);
    }
}
