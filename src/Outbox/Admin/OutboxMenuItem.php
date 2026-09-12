<?php

declare(strict_types=1);

namespace App\Outbox\Admin;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

/**
 * Labels here are rendered raw by the bundle's sidebar template, which has no
 * translator available, so they stay in English like the bundle's own strings.
 */
final class OutboxMenuItem implements AdminMenuItemInterface
{
    #[\Override]
    public function getLabel(): string
    {
        return 'Agent outbox'; // @translation-check-ignore
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'inbox';
    }

    #[\Override]
    public function getRouteName(): string
    {
        return 'app_admin_outbox_list';
    }

    #[\Override]
    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_outbox_';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 30;
    }
}
