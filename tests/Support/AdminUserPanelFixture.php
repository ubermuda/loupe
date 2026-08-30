<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;

/**
 * A panel contributor registered only in the test environment, so that the
 * admin user detail page stays panel-free for every other test and for
 * production. It abstains unless the account's email carries MARKER.
 */
final readonly class AdminUserPanelFixture implements AdminUserPanelInterface
{
    public const string MARKER = 'panel-fixture';

    public function __construct(
        private int $order,
        private string $label,
    ) {
    }

    #[\Override]
    public function panelOrder(): int
    {
        return $this->order;
    }

    #[\Override]
    public function panelFor(User $user): ?AdminUserPanel
    {
        if (!str_contains($user->email, self::MARKER)) {
            return null;
        }

        return new AdminUserPanel('@TestFixtures/admin_user_panel.html.twig', ['label' => $this->label]);
    }
}
