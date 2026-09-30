<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;
use App\Module\Billing\Repository\BetaInviteRepository;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Marks an account that joined through a beta invite. The context carries no
 * Billing type, because Account renders it with `only`.
 */
#[AsTaggedItem(priority: 8)]
final readonly class BetaInviteAdminUserPanel implements AdminUserPanelInterface
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
    ) {
    }

    #[\Override]
    public function panelFor(User $user): ?AdminUserPanel
    {
        $invite = $this->betaInvites->findOneRedeemedBy($user);
        if (null === $invite) {
            return null;
        }

        return new AdminUserPanel('@Billing/admin/beta_invite_panel.html.twig', [
            'redeemedAt' => $invite->redeemedAt,
            'note' => $invite->note,
        ]);
    }
}
