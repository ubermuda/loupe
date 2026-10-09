<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/**
 * A GitHub account connection that a person started and GitHub has not sent
 * back. The project and the origin name the page that opened the popup, and
 * both are null when the person came from the Connected apps page.
 */
final readonly class PendingGitHubConnect
{
    public function __construct(
        public string $state,
        public string $codeVerifier,
        public ?string $projectId = null,
        public ?string $origin = null,
    ) {
    }

    /** The S256 challenge of the verifier, base64url with no padding. */
    public function codeChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
    }
}
