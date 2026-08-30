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
 * Symfony's tagged_iterator does not guarantee a stable iteration order, so
 * position is explicit through panelOrder(), the same way
 * AccountDataPurgerInterface orders its purgers.
 */
#[AutoconfigureTag('app.admin_user_panel')]
interface AdminUserPanelInterface
{
    /** Lower numbers render first. */
    public function panelOrder(): int;

    /** Returns null when this module has nothing to show for $user. */
    public function panelFor(User $user): ?AdminUserPanel;
}
