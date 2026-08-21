<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\DataExportRepository;

final readonly class ShowUserHandler
{
    public function __construct(
        private ConnectedAccountRepository $connectedAccounts,
        private ApiTokenRepository $apiTokens,
        private DataExportRepository $dataExports,
    ) {
    }

    public function __invoke(ShowUserCommand $command): UserDetailView
    {
        return new UserDetailView(
            user: $command->target,
            connectedAccounts: $this->connectedAccounts->findByUser($command->target),
            apiTokenCount: $this->apiTokens->countActiveByOwner($command->target),
            dataExports: $this->dataExports->findByUser($command->target),
        );
    }
}
