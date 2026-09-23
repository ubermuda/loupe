<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Carries an App install across the trip to GitHub. The setup URL is fixed on
 * the App and cannot name the project, so the session holds it. One pending
 * install per session: a new one replaces the old.
 */
final readonly class GitHubInstallSession
{
    private const string KEY = 'github_app_install';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /** @return string the state to send to GitHub with the install link */
    public function begin(Project $project): string
    {
        $pending = new PendingGitHubInstall((string) $project->id, bin2hex(random_bytes(16)));
        $this->store($pending);

        return $pending->installState;
    }

    public function pending(): ?PendingGitHubInstall
    {
        $data = $this->requestStack->getSession()->get(self::KEY);
        if (!\is_array($data) || !\is_string($data['projectId'] ?? null) || !\is_string($data['installState'] ?? null)) {
            return null;
        }

        return new PendingGitHubInstall(
            $data['projectId'],
            $data['installState'],
            \is_int($data['installationId'] ?? null) ? $data['installationId'] : null,
            \is_string($data['authorizeState'] ?? null) ? $data['authorizeState'] : null,
            \is_string($data['codeVerifier'] ?? null) ? $data['codeVerifier'] : null,
        );
    }

    /** Binds the installation GitHub named, and mints the state and the PKCE verifier of the authorization. */
    public function authorize(PendingGitHubInstall $pending, int $installationId): PendingGitHubInstall
    {
        $authorizing = new PendingGitHubInstall(
            $pending->projectId,
            $pending->installState,
            $installationId,
            bin2hex(random_bytes(16)),
            rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        );
        $this->store($authorizing);

        return $authorizing;
    }

    /** Reads the pending install and forgets it, so each callback runs once. */
    public function take(): ?PendingGitHubInstall
    {
        $pending = $this->pending();
        $this->clear();

        return $pending;
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::KEY);
    }

    private function store(PendingGitHubInstall $pending): void
    {
        $this->requestStack->getSession()->set(self::KEY, [
            'projectId' => $pending->projectId,
            'installState' => $pending->installState,
            'installationId' => $pending->installationId,
            'authorizeState' => $pending->authorizeState,
            'codeVerifier' => $pending->codeVerifier,
        ]);
    }
}
