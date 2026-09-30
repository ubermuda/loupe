<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowInstallScriptControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_an_anonymous_caller_gets_the_script_for_this_instance(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/install.sh');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        self::assertResponseHeaderSame('Cache-Control', 'max-age=300, public');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        $lines = explode("\n", (string) $client->getResponse()->getContent());
        self::assertSame(['#!/bin/sh', "LOUPE_URL='http://localhost'", "LOUPE_CLI_MAJOR='1'"], array_slice($lines, 0, 3));
    }

    /** A browser that holds a session must get the same shared, cacheable answer. */
    public function test_a_signed_in_caller_gets_a_public_answer(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'install-script@example.com');
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/install.sh');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=300, public');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }
}
