<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Authorization server metadata (RFC 8414). The issuer is the configured
 * DEFAULT_URI, and every `iss` parameter the authorize endpoint sends matches it.
 */
final readonly class ShowAuthorizationServerMetadataHandler
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $issuer,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(ShowAuthorizationServerMetadataCommand $command): array
    {
        $issuer = rtrim($this->issuer, '/');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.$this->urls->generate('oauth2_authorize'),
            'token_endpoint' => $issuer.$this->urls->generate('oauth2_token'),
            'scopes_supported' => array_map(static fn (ApiTokenScope $scope): string => $scope->value, ApiTokenScope::cases()),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
        ];
    }
}
