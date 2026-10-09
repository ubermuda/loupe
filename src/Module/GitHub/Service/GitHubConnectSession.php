<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/** Carries a GitHub account connection across the trip to GitHub. One per session: a new one replaces the old. */
final readonly class GitHubConnectSession
{
    private const string KEY = 'github_user_connect';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function begin(?string $projectId, ?string $origin): PendingGitHubConnect
    {
        $pending = new PendingGitHubConnect(
            bin2hex(random_bytes(16)),
            rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            $projectId,
            $origin,
        );
        $this->requestStack->getSession()->set(self::KEY, [
            'state' => $pending->state,
            'codeVerifier' => $pending->codeVerifier,
            'projectId' => $pending->projectId,
            'origin' => $pending->origin,
        ]);

        return $pending;
    }

    /** Reads the pending connection and forgets it, so each callback runs once. */
    public function take(): ?PendingGitHubConnect
    {
        $session = $this->requestStack->getSession();
        $data = $session->get(self::KEY);
        $session->remove(self::KEY);
        if (!\is_array($data) || !\is_string($data['state'] ?? null) || !\is_string($data['codeVerifier'] ?? null)) {
            return null;
        }

        return new PendingGitHubConnect(
            $data['state'],
            $data['codeVerifier'],
            \is_string($data['projectId'] ?? null) ? $data['projectId'] : null,
            \is_string($data['origin'] ?? null) ? $data['origin'] : null,
        );
    }
}
