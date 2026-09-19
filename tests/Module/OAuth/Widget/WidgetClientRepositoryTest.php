<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Widget;

use App\Module\OAuth\Widget\WidgetClient;
use App\Tests\Support\OAuthScenario;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WidgetClientRepositoryTest extends KernelTestCase
{
    public function test_the_migration_registers_the_widget_as_a_public_client(): void
    {
        $clients = static::getContainer()->get('league.oauth2_server.repository.client');
        self::assertInstanceOf(ClientRepositoryInterface::class, $clients);

        $client = $clients->getClientEntity(WidgetClient::ID);

        self::assertNotNull($client);
        self::assertFalse($client->isConfidential());
        self::assertTrue($clients->validateClient(WidgetClient::ID, null, 'authorization_code'));
        self::assertTrue($clients->validateClient(WidgetClient::ID, null, 'refresh_token'));
        self::assertFalse($clients->validateClient(WidgetClient::ID, null, 'client_credentials'));
    }

    public function test_the_redirect_uri_is_the_callback_on_this_instance(): void
    {
        $clients = static::getContainer()->get('league.oauth2_server.repository.client');
        self::assertInstanceOf(ClientRepositoryInterface::class, $clients);
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);

        $client = $clients->getClientEntity(WidgetClient::ID);

        self::assertNotNull($client);
        self::assertSame([rtrim($appUrl, '/').'/oauth/widget/callback'], $client->getRedirectUri());
    }

    public function test_another_client_keeps_its_own_redirect_uris(): void
    {
        new OAuthScenario(static::getContainer())->createClient();
        $clients = static::getContainer()->get('league.oauth2_server.repository.client');
        self::assertInstanceOf(ClientRepositoryInterface::class, $clients);

        $client = $clients->getClientEntity(OAuthScenario::CLIENT_ID);

        self::assertNotNull($client);
        self::assertSame([OAuthScenario::REDIRECT_URI], $client->getRedirectUri());
    }
}
