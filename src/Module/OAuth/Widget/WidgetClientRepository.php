<?php

declare(strict_types=1);

namespace App\Module\OAuth\Widget;

use League\Bundle\OAuth2ServerBundle\Entity\Client;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Gives the widget client its redirect URI at runtime. The callback lives on
 * this instance, and a URI stored at migration time would name whatever host
 * ran the migration.
 */
#[AsDecorator('league.oauth2_server.repository.client')]
final readonly class WidgetClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ClientRepositoryInterface $inner,

        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    #[\Override]
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->inner->getClientEntity($clientIdentifier);
        if (WidgetClient::ID === $clientIdentifier && $client instanceof Client) {
            $client->setRedirectUri([WidgetClient::redirectUri($this->appUrl)]);
        }

        return $client;
    }

    #[\Override]
    public function validateClient(string $clientIdentifier, #[\SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        return $this->inner->validateClient($clientIdentifier, $clientSecret, $grantType);
    }
}
