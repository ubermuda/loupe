<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubConnectSession;
use App\Module\Project\Service\SiteOrigins;

final readonly class StartGitHubConnectHandler
{
    public function __construct(
        private GitHubAppConfiguration $appConfiguration,
        private GitHubConnectSession $connectSession,
    ) {
    }

    /** @return string the OAuth authorize page on GitHub */
    public function __invoke(StartGitHubConnectCommand $command): string
    {
        if (!$this->appConfiguration->isConfigured()) {
            throw new DomainErrors(['connection' => 'github.connect.flash.unavailable']);
        }

        $origin = null === $command->origin ? null : SiteOrigins::normalise($command->origin);
        $pending = $this->connectSession->begin(null === $origin ? null : $command->projectId, $origin);

        return $this->appConfiguration->authorizeUrl($command->redirectUri, $pending->state, $pending->codeChallenge());
    }
}
