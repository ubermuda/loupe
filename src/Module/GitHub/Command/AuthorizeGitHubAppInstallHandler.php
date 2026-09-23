<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInstallSession;

/**
 * GitHub may or may not send the install state back, so a state is checked
 * only when present. The user authorization that follows proves the
 * installation either way.
 */
final readonly class AuthorizeGitHubAppInstallHandler
{
    public function __construct(
        private GitHubAppConfiguration $appConfiguration,
        private GitHubInstallSession $installSession,
    ) {
    }

    /** @return string the OAuth authorize page on GitHub */
    public function __invoke(AuthorizeGitHubAppInstallCommand $command): string
    {
        $error = match (true) {
            null !== $command->state && !hash_equals($command->pending->installState, $command->state) => 'github.app.flash.state_mismatch',
            $command->installationId <= 0 => 'github.app.flash.no_installation',
            !$this->appConfiguration->isConfigured() => 'github.app.flash.unavailable',
            default => null,
        };
        if (null !== $error) {
            $this->installSession->clear();

            throw new DomainErrors(['installation' => $error]);
        }

        $authorizing = $this->installSession->authorize($command->pending, $command->installationId);

        return $this->appConfiguration->authorizeUrl(
            $command->redirectUri,
            $authorizing->authorizeState ?? throw new \LogicException('authorize() mints the state'),
            $authorizing->codeChallenge() ?? throw new \LogicException('authorize() mints the verifier'),
        );
    }
}
