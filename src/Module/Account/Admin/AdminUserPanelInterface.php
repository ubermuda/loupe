<?php

declare(strict_types=1);

namespace App\Module\Account\Admin;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per module; each contributes a panel to the admin user
 * detail page. Tagged and collected by ShowUserHandler, which owns the
 * iteration while each module owns its own panel. The dependency points a
 * module at Account, never Account at a module.
 *
 * An implementation declares its position with #[AsTaggedItem(priority: N)].
 * A higher priority renders first. Symfony sorts the tagged iterator by that
 * priority, so ShowUserHandler consumes the panels in order.
 */
#[AutoconfigureTag('app.admin_user_panel')]
interface AdminUserPanelInterface
{
    /** Returns null when this module has nothing to show for $user. */
    public function panelFor(User $user): ?AdminUserPanel;
}
