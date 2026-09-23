<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\GitHub\Repository\GitHubHookRepository;

/** Omits the secret, because an export can travel further than the account it came from. */
final readonly class GitHubHookExporter implements UserDataExporterInterface
{
    public function __construct(
        private GitHubHookRepository $gitHubHooks,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'github_hooks.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->gitHubHooks->findExportRowsByOwner($user) as $hook) {
            yield [
                'project' => $hook['projectName'],
                'hookKey' => $hook['hookKey'],
                'lastAcceptedAt' => $hook['lastAcceptedAt']?->format(\DateTimeInterface::ATOM),
                'lastRefusedAt' => $hook['lastRefusedAt']?->format(\DateTimeInterface::ATOM),
                'lastRefusedReason' => $hook['lastRefusedReason'],
                'createdAt' => $hook['createdAt']->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
