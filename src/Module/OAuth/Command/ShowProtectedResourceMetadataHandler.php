<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Service\McpResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Protected resource metadata (RFC 9728) for the MCP endpoint, the one resource OAuth clients reach by URL. */
final readonly class ShowProtectedResourceMetadataHandler
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $issuer,
        private McpResource $mcpResource,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(ShowProtectedResourceMetadataCommand $command): array
    {
        return [
            'resource' => $this->mcpResource->uri,
            'authorization_servers' => [rtrim($this->issuer, '/')],
            'scopes_supported' => ['mcp'],
            'bearer_methods_supported' => ['header'],
        ];
    }
}
