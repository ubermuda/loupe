<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Whether the operator registered a GitHub App for this instance. */
final readonly class GitHubAppConfiguration
{
    public function __construct(
        #[Autowire(env: 'default::GITHUB_APP_SLUG')]
        public ?string $slug,

        #[Autowire(env: 'default::GITHUB_APP_CLIENT_ID')]
        public ?string $clientId,

        #[Autowire(env: 'default::GITHUB_APP_CLIENT_SECRET')]
        public ?string $clientSecret,

        #[Autowire(env: 'default::GITHUB_APP_WEBHOOK_SECRET')]
        private ?string $webhookSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return [] === $this->missingVariables();
    }

    public function isUnset(): bool
    {
        return \count($this->missingVariables()) === \count($this->values());
    }

    /** @return list<non-empty-string> the names of the unset variables, never their values */
    public function missingVariables(): array
    {
        return array_keys(array_filter($this->values(), fn (?string $value): bool => null === $value || '' === $value));
    }

    /** @return array<non-empty-string, ?string> */
    private function values(): array
    {
        return [
            'GITHUB_APP_SLUG' => $this->slug,
            'GITHUB_APP_CLIENT_ID' => $this->clientId,
            'GITHUB_APP_CLIENT_SECRET' => $this->clientSecret,
            'GITHUB_APP_WEBHOOK_SECRET' => $this->webhookSecret,
        ];
    }

    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/'.rawurlencode((string) $this->slug).'/installations/new?'.http_build_query(['state' => $state]);
    }

    public function authorizeUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }
}
