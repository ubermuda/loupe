<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaCompGranter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ignores `billing.enabled`: a comp is open-ended, so it cannot start a trial
 * clock that ticks down unwatched. The negative priority runs it after
 * ProvisionTrialOnUserRegistered.
 */
#[AsEventListener(priority: -10)]
final readonly class GrantBetaCompOnUserRegistered
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BetaCompGranter $grantBetaComp,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        // A throw here would skip every later listener, and the account exists.
        try {
            $invite = $this->betaInvites->findOneRedeemedBy($event->user);
            if (null !== $invite) {
                ($this->grantBetaComp)($invite);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('billing.beta_comp_failed', [
                'userId' => (string) $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
