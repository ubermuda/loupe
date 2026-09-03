<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RevokeCompHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the account holds no current comp */
    public function __invoke(RevokeCompCommand $command): Subscription
    {
        $profile = $this->billingProfiles->findOneByUser($command->target);
        if (null === $profile) {
            throw new DomainErrors(['comp' => 'billing.admin.comp.error.not_comped']);
        }

        // A DBAL transaction, for the same reason the grant path uses one: a
        // DomainErrors thrown out of wrapInTransaction() closes the shared
        // EntityManager.
        [$comp, $disabled] = $this->em->getConnection()->transactional(
            /** @return array{Subscription, bool} */
            function () use ($profile): array {
                $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($profile);

                $now = new \DateTimeImmutable();
                $comp = $profile->currentSubscriptionOfKind(SubscriptionKind::Comp, $now);
                if (!$comp instanceof Subscription) {
                    throw new DomainErrors(['comp' => 'billing.admin.comp.error.not_comped']);
                }

                // An end date rather than a delete, so the trail of who was comped
                // and when survives the revocation.
                $comp->endsAt = $now;

                // isCurrent() stops at endsAt, so the comp just ended is already
                // out of this count. The sweep and a cancellation leave an account
                // with nothing running in exactly this state.
                $user = $profile->user;
                $disabled = null === $user->disabledAt && !$profile->hasCurrentSubscription($now);
                if ($disabled) {
                    $user->disabledAt = $now;
                }

                $this->em->flush();

                return [$comp, $disabled];
            },
        );

        $this->record('billing.comp_revoked', $command);

        if ($disabled) {
            $this->record('billing.account_disabled_on_comp_revoke', $command);
        }

        return $comp;
    }

    /**
     * The comped account, never the admin: the admin is the actor, and the
     * Auditor resolves that from the security token by itself.
     */
    private function record(string $operation, RevokeCompCommand $command): void
    {
        $this->auditor->record(
            $operation,
            AuditOutcome::Success,
            ['userId' => (string) $command->target->id],
            new AuditSubject('user', (string) $command->target->id),
        );
    }
}
