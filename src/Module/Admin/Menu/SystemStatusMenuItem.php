<?php

declare(strict_types=1);

namespace App\Module\Admin\Menu;

use Ubermuda\AdminBundle\Menu\NonPrefetchableAdminMenuItem;

/**
 * Contributes the "System status" entry to the admin sidebar.
 *
 * Auto-tagged `app.admin_menu_item` by the admin bundle's instanceof
 * autoconfiguration; no service wiring is needed.
 *
 * Labels here are rendered raw by the bundle's sidebar template, which has no
 * translator available, so they stay in English like the bundle's own strings.
 *
 * Non-prefetchable because rendering the status page opens an SMTP connection
 * to the configured mailer and issues an HTTP GET to the Mercure hub, each
 * bounded at 3 seconds. Turbo prefetches sidebar links on hover, so without the
 * opt-out merely pointing at this entry fires both probes against third-party
 * infrastructure with no user intent behind it.
 */
final class SystemStatusMenuItem implements NonPrefetchableAdminMenuItem
{
    public function getLabel(): string
    {
        return 'System status'; // @translation-check-ignore
    }

    public function getIcon(): string
    {
        return 'activity';
    }

    public function getRouteName(): string
    {
        return 'app_admin_diagnostics';
    }

    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_diagnostics';
    }

    public function getPriority(): int
    {
        return 90;
    }
}
