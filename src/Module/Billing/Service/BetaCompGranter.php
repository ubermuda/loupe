<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Exception\DomainErrors;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BetaInvite;
use Psr\Log\LoggerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Runs after the redemption commits. It never throws: the invite is already
 * used and the account already exists, so a failed grant is logged and the
 * account keeps its trial.
 */
final readonly class BetaCompGranter
{
    public function __construct(
        private GrantCompHandler $grantComp,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BetaInvite $invite): void
    {
        $user = $invite->redeemedBy ?? throw new \LogicException('Only a redeemed beta invite grants a comp.');
        $context = ['betaInviteId' => (string) $invite->id, 'userId' => (string) $user->id];

        // The audit record must not cost the tester the comp, so it comes second.
        try {
            ($this->grantComp)(new GrantCompCommand($user, $invite->createdBy));
        } catch (DomainErrors $e) {
            $this->logger->info('billing.beta_comp_skipped', $context + ['errors' => $e->errors]);
        } catch (\Throwable $e) {
            $this->logger->warning('billing.beta_comp_failed', $context + ['error' => $e->getMessage()]);
        }

        $this->auditor->record(
            'billing.beta_invite_redeemed',
            AuditOutcome::Success,
            $context,
            new AuditSubject('beta_invite', (string) $invite->id),
        );
    }
}
