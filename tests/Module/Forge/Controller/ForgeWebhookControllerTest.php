<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Controller;

use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * A forge signs its body and carries no session, so the endpoint must answer an
 * anonymous request. A firewall rule that diverts it to the login page reads, on
 * the forge's side, as a delivery that succeeded, and it retries for days.
 */
final class ForgeWebhookControllerTest extends WebTestCase
{
    public function test_an_anonymous_delivery_reaches_the_endpoint(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_POST,
            '/webhooks/forge/nosuchforge',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertTrue($client->getRequest()->attributes->get(RateLimitForgeDeliveries::MARKER), 'The rate limiter reads the route default from the request.');
        self::assertJsonStringEqualsJsonString(
            '{"error":"unknown forge"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_the_route_is_rate_limited_by_client_address(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('webhook_forge');

        self::assertNotNull($route);
        self::assertTrue($route->getDefault(RateLimitForgeDeliveries::MARKER));
        self::assertSame(RateLimitForgeDeliveries::KEY_BY_ADDRESS, $route->getDefault(RateLimitForgeDeliveries::KEYING));
    }
}
