<?php

declare(strict_types=1);

namespace App\Module\Billing\Admin;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

final class BetaInvitesMenuItem implements AdminMenuItemInterface
{
    #[\Override]
    public function getLabel(): string
    {
        // The bundle's sidebar template has no translator, so labels stay raw.
        return 'Beta invites'; // @translation-check-ignore
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'link';
    }

    #[\Override]
    public function getRouteName(): string
    {
        return 'app_admin_beta_invites_list';
    }

    #[\Override]
    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_beta_invites_';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 35;
    }
}
