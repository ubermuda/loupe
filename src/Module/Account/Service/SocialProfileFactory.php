<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;

final readonly class SocialProfileFactory
{
    public function __construct(
        private GithubPrimaryEmailFetcher $githubEmails,
    ) {
    }

    public function fromResourceOwner(SocialProvider $provider, ResourceOwnerInterface $owner, string $accessToken): SocialProfile
    {
        $data = $owner->toArray();

        return match ($provider) {
            // Google exposes an `email_verified` claim (bool, sometimes the string
            // "true"); FILTER_VALIDATE_BOOL normalises both.
            SocialProvider::Google => new SocialProfile(
                $provider,
                (string) $owner->getId(),
                $this->str($data['email'] ?? null),
                $this->str($data['name'] ?? null),
                emailVerified: filter_var($data['email_verified'] ?? false, \FILTER_VALIDATE_BOOL),
            ),
            // GitHub has no email_verified claim and /user only exposes the public
            // profile email, so the verified primary email is fetched from
            // /user/emails. A fetch failure degrades to "unverified" — the raw
            // public email is kept for reference but never trusted for matching.
            SocialProvider::Github => $this->github($data, (string) $owner->getId(), $accessToken),
        };
    }

    /** @param array<string, mixed> $data */
    private function github(array $data, string $id, string $accessToken): SocialProfile
    {
        $primary = $this->githubEmails->fetchPrimary($accessToken);

        return new SocialProfile(
            SocialProvider::Github,
            $id,
            $primary['email'] ?? $this->str($data['email'] ?? null),
            $this->str($data['name'] ?? null) ?? $this->str($data['login'] ?? null),
            emailVerified: true === ($primary['verified'] ?? false),
        );
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
