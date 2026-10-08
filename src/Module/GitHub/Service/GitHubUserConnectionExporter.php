<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;

/** Exports the account and the dates of the connection, never a token. */
final readonly class GitHubUserConnectionExporter implements UserDataExporterInterface
{
    public function __construct(
        private GitHubUserConnectionRepository $gitHubUserConnections,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'github_user_connection.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $connection = $this->gitHubUserConnections->findSummaryByUser($user);
        if (null === $connection) {
            return;
        }

        yield [
            'login' => $connection->login,
            'connectedAt' => $connection->connectedAt->format(\DateTimeInterface::ATOM),
            'expiredAt' => $connection->expiredAt?->format(\DateTimeInterface::ATOM),
            'accessTokenExpiresAt' => $connection->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refreshTokenExpiresAt' => $connection->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
