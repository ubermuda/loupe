<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInstallSession;

final readonly class StartGitHubAppInstallHandler
{
    public function __construct(
        private GitHubAppConfiguration $appConfiguration,
        private GitHubInstallSession $installSession,
    ) {
    }

    /** @return string the install page of the App on GitHub */
    public function __invoke(StartGitHubAppInstallCommand $command): string
    {
        if (!$this->appConfiguration->isConfigured()) {
            throw new DomainErrors(['app' => 'github.app.flash.unavailable']);
        }

        return $this->appConfiguration->installUrl($this->installSession->begin($command->project));
    }
}
