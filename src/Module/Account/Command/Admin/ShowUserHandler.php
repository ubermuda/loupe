<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\DataExportRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ShowUserHandler
{
    /** @var list<AdminUserPanelInterface> */
    private array $panelContributors;

    /** @param iterable<AdminUserPanelInterface> $panelContributors */
    public function __construct(
        private ConnectedAccountRepository $connectedAccounts,
        private ApiTokenRepository $apiTokens,
        private DataExportRepository $dataExports,

        #[AutowireIterator('app.admin_user_panel')]
        iterable $panelContributors,
    ) {
        $ordered = iterator_to_array($panelContributors, false);
        usort($ordered, static fn (AdminUserPanelInterface $a, AdminUserPanelInterface $b): int => $a->panelOrder() <=> $b->panelOrder());
        $this->panelContributors = $ordered;
    }

    public function __invoke(ShowUserCommand $command): UserDetailView
    {
        $panels = [];
        foreach ($this->panelContributors as $contributor) {
            $panel = $contributor->panelFor($command->target);
            if ($panel instanceof AdminUserPanel) {
                $panels[] = $panel;
            }
        }

        return new UserDetailView(
            user: $command->target,
            connectedAccounts: $this->connectedAccounts->findByUser($command->target),
            apiTokenCount: $this->apiTokens->countActiveByOwner($command->target),
            dataExports: $this->dataExports->findByUser($command->target),
            panels: $panels,
        );
    }
}
