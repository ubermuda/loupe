<?php

declare(strict_types=1);

namespace App\Module\Account\Admin;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

final class WaitlistMenuItem implements AdminMenuItemInterface
{
    #[\Override]
    public function getLabel(): string
    {
        return 'Waitlist';
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'list-plus';
    }

    #[\Override]
    public function getRouteName(): string
    {
        return 'app_admin_waitlist_list';
    }

    #[\Override]
    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_waitlist_';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 40;
    }
}
