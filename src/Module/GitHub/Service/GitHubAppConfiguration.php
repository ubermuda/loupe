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
        private ?string $clientSecret,

        #[Autowire(env: 'default::GITHUB_APP_WEBHOOK_SECRET')]
        private ?string $webhookSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return array_all([$this->slug, $this->clientId, $this->clientSecret, $this->webhookSecret], fn ($value) => !(null === $value || '' === $value));
    }
}
