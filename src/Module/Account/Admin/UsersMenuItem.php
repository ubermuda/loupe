<?php

declare(strict_types=1);

namespace App\Module\Account\Admin;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

final class UsersMenuItem implements AdminMenuItemInterface
{
    #[\Override]
    public function getLabel(): string
    {
        // The bundle's sidebar template has no translator, so labels stay raw.
        return 'Users'; // @translation-check-ignore
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'users';
    }

    #[\Override]
    public function getRouteName(): string
    {
        return 'app_admin_users_list';
    }

    #[\Override]
    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_users_';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 60;
    }
}
