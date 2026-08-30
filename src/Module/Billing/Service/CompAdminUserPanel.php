<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Billing's contribution to the admin user detail page: whether the account is
 * comped, and the action that grants or revokes it. The context carries scalars
 * only, because the template gets it with `only` and Account must not learn a
 * Billing type.
 */
#[AsTaggedItem(priority: 10)]
final readonly class CompAdminUserPanel implements AdminUserPanelInterface
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private FeatureFlagService $featureFlags,
    ) {
    }

    #[\Override]
    public function panelFor(User $user): ?AdminUserPanel
    {
        $comp = $this->billingProfiles->findOneByUser($user)
            ?->currentSubscriptionOfKind(SubscriptionKind::Comp, new \DateTimeImmutable());

        // While the paywall is off nobody needs a comp, so the panel abstains.
        // A comp that already exists still shows, so an admin can revoke it.
        if (!$comp instanceof Subscription && !$this->featureFlags->isEnabled('billing.enabled')) {
            return null;
        }

        return new AdminUserPanel('@Billing/admin/comp_panel.html.twig', [
            'targetUserId' => (string) $user->id,
            'comped' => $comp instanceof Subscription,
            'grantedBy' => $comp?->grantedBy?->email,
            'grantedAt' => $comp?->startsAt,
        ]);
    }
}
