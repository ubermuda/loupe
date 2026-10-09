<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Service\ForgeUserAccount;
use App\Module\Forge\Service\ForgeUserAccountReader;
use App\Module\Forge\Service\ForgeUserConnectionState;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GitHubUserAccountReader implements ForgeUserAccountReader
{
    public function __construct(
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function accountOf(Uuid $userId): ForgeUserAccount
    {
        $summary = $this->gitHubUserConnections->findSummaryByUserId($userId);
        if (null === $summary) {
            return new ForgeUserAccount(ForgeUserConnectionState::None);
        }

        return new ForgeUserAccount(
            $summary->isExpired($this->clock->now()) ? ForgeUserConnectionState::Expired : ForgeUserConnectionState::Connected,
            (string) $summary->githubUserId,
        );
    }
}
