<?php

declare(strict_types=1);

namespace App\Module\Admin\Menu;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

/**
 * Contributes the "Dashboard" entry to the admin sidebar.
 *
 * Auto-tagged `app.admin_menu_item` by the admin bundle's instanceof
 * autoconfiguration; no service wiring is needed.
 *
 * Labels here are rendered raw by the bundle's sidebar template, which has no
 * translator available, so they stay in English like the bundle's own strings.
 */
final class DashboardMenuItem implements AdminMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Dashboard'; // @translation-check-ignore
    }

    public function getIcon(): string
    {
        return 'layout-dashboard';
    }

    public function getRouteName(): string
    {
        return 'app_admin_dashboard';
    }

    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_dashboard';
    }

    public function getPriority(): int
    {
        return 100;
    }
}
