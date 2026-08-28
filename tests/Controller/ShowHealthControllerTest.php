<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoint itself belongs to ubermuda/health-check-bundle; what this app
 * has to prove is that it is mounted, reachable without a session, and reports
 * nothing to an anonymous caller.
 */
final class ShowHealthControllerTest extends WebTestCase
{
    public function test_anonymous_probe_gets_200_and_an_ok_body(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/healthz');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_a_probe_token_the_instance_never_configured_reveals_nothing(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/healthz', server: ['HTTP_X_PROBE_TOKEN' => 'anything']);

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }
}
