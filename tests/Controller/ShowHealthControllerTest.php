<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\BuildIdentity;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoint itself belongs to ubermuda/health-check-bundle; what this app
 * has to prove is that it is mounted, reachable without a session, reports
 * nothing to an anonymous caller, and that our own BuildIdentity reaches it as
 * a metadata provider.
 */
final class ShowHealthControllerTest extends WebTestCase
{
    private const string PROBE_TOKEN = 'right-token';

    #[\Override]
    protected function tearDown(): void
    {
        unset($_ENV['HEALTH_PROBE_TOKEN'], $_SERVER['HEALTH_PROBE_TOKEN']);

        parent::tearDown();
    }

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

    /**
     * Present-and-null, not absent: a post-deploy check holding the token has
     * to tell "this build is unidentified" from "your token was refused", and
     * dropping the null would make those two answers the same body.
     */
    public function test_a_correct_probe_token_gets_the_version_even_when_there_is_none(): void
    {
        $client = $this->clientWithProbeToken();

        $client->request(Request::METHOD_GET, '/healthz', server: ['HTTP_X_PROBE_TOKEN' => self::PROBE_TOKEN]);

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","version":null}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_a_wrong_probe_token_gets_no_version_field_at_all(): void
    {
        $client = $this->clientWithProbeToken();

        $client->request(Request::METHOD_GET, '/healthz', server: ['HTTP_X_PROBE_TOKEN' => 'wrong-token']);

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * The token is read from the environment, so it has to be in place before
     * the container resolves it. BuildIdentity is pointed at a directory with
     * no build-version file, so the version is null whatever this checkout
     * happens to carry.
     */
    private function clientWithProbeToken(): KernelBrowser
    {
        $_ENV['HEALTH_PROBE_TOKEN'] = self::PROBE_TOKEN;
        $_SERVER['HEALTH_PROBE_TOKEN'] = self::PROBE_TOKEN;

        $client = static::createClient();
        $unbuilt = sys_get_temp_dir().'/loupe-health-'.bin2hex(random_bytes(6));
        self::getContainer()->set(BuildIdentity::class, new BuildIdentity($unbuilt));

        return $client;
    }
}
