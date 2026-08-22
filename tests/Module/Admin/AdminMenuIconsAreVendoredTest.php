<?php

declare(strict_types=1);

namespace App\Tests\Module\Admin;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AdminBundle\Menu\AdminMenuRegistry;

/**
 * The sidebar renders `lucide:{{ item.icon }}`, a name assembled at runtime, so
 * `ux:icons:lock` cannot see it and never vendors those SVGs. `on_demand` hides
 * that everywhere but production, where it is off and `ignore_not_found` renders
 * the missing icon as nothing at all — no error, just a gap in the nav.
 */
final class AdminMenuIconsAreVendoredTest extends KernelTestCase
{
    public function test_every_admin_menu_icon_is_vendored(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(AdminMenuRegistry::class);
        $iconDir = \dirname(__DIR__, 3).'/assets/icons/lucide';

        $items = $registry->items();
        // Without this the loop below is empty and the test passes on a broken
        // registry, proving nothing about any icon.
        self::assertNotEmpty($items, 'Expected registered admin menu items.');

        $missing = [];
        foreach ($items as $item) {
            $icon = $item->getIcon();
            if (!is_file($iconDir.'/'.$icon.'.svg')) {
                $missing[] = sprintf('%s (%s)', $icon, $item::class);
            }
        }

        self::assertSame(
            [],
            $missing,
            "Admin menu icons missing from assets/icons/lucide — production renders these as nothing.\n"
            .'Import them: bin/console ux:icons:import '.implode(' ', array_map(
                static fn (string $m): string => 'lucide:'.strtok($m, ' '),
                $missing,
            )),
        );
    }
}
