<?php

declare(strict_types=1);

namespace App\Tests\Forge\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
        self::assertJsonStringEqualsJsonString(
            '{"error":"unknown forge"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
