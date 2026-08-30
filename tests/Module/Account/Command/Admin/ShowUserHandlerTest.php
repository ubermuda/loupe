<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Command\Admin\ShowUserCommand;
use App\Module\Account\Command\Admin\ShowUserHandler;
use App\Module\Account\Command\Admin\UserDetailView;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\DataExportRepository;
use PHPUnit\Framework\TestCase;

final class ShowUserHandlerTest extends TestCase
{
    public function test_a_page_with_no_contributors_has_no_panels(): void
    {
        $view = $this->handle([]);

        self::assertSame([], $view->panels);
    }

    public function test_panels_arrive_in_their_declared_position(): void
    {
        $view = $this->handle([
            $this->panel(20, 'second'),
            $this->panel(10, 'first'),
        ]);

        self::assertSame(
            ['first', 'second'],
            array_map(static fn (AdminUserPanel $panel): string => (string) $panel->context['label'], $view->panels),
        );
    }

    public function test_a_contributor_that_abstains_adds_no_panel(): void
    {
        $view = $this->handle([
            $this->panel(10, 'first'),
            $this->panel(20, 'second', abstains: true),
        ]);

        self::assertCount(1, $view->panels);
        self::assertSame('first', $view->panels[0]->context['label']);
    }

    private function panel(int $order, string $label, bool $abstains = false): AdminUserPanelInterface
    {
        return new readonly class($order, $label, $abstains) implements AdminUserPanelInterface {
            public function __construct(
                private int $order,
                private string $label,
                private bool $abstains,
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
                if ($this->abstains) {
                    return null;
                }

                return new AdminUserPanel('@TestFixtures/admin_user_panel.html.twig', ['label' => $this->label]);
            }
        };
    }

    /**
     * @param list<AdminUserPanelInterface> $contributors
     */
    private function handle(array $contributors): UserDetailView
    {
        $connectedAccounts = self::createStub(ConnectedAccountRepository::class);
        $connectedAccounts->method('findByUser')->willReturn([]);

        $apiTokens = self::createStub(ApiTokenRepository::class);
        $apiTokens->method('countActiveByOwner')->willReturn(0);

        $dataExports = self::createStub(DataExportRepository::class);
        $dataExports->method('findByUser')->willReturn([]);

        $handler = new ShowUserHandler($connectedAccounts, $apiTokens, $dataExports, $contributors);

        return $handler(new ShowUserCommand(new User(fullName: 'Trillian Astra', email: 'panels@example.com', password: 'x')));
    }
}
