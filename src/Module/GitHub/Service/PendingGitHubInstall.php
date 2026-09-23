<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** An App install that a person started and GitHub has not yet sent back. */
final readonly class PendingGitHubInstall
{
    public function __construct(
        public string $projectId,
        public string $installState,
        public ?int $installationId = null,
        public ?string $authorizeState = null,
        public ?string $codeVerifier = null,
    ) {
    }

    /** The S256 challenge of the verifier, base64url with no padding. */
    public function codeChallenge(): ?string
    {
        if (null === $this->codeVerifier) {
            return null;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
    }
}
