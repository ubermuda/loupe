<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Command\CheckDatabaseHealthHandler;
use App\Controller\ShowHealthController;
use App\Service\BuildIdentity;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

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

    public function test_unreachable_database_gets_503_and_leaks_nothing_about_the_failure(): void
    {
        /** @var Connection&Stub $connection */
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[08006] could not connect to server: host=db.internal user=app password=hunter2'),
        );

        // The real handler, so the assertions below still cover the whole path
        // from the driver exception to the rendered body.
        $handler = new CheckDatabaseHealthHandler($connection, new NullLogger());

        $response = (new ShowHealthController($handler, self::buildIdentity(null)))(new Request());

        self::assertSame(503, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertJsonStringEqualsJsonString('{"status":"error"}', $body);
        // The exception message carries the database host, user and password;
        // an unauthenticated probe must never see any of it.
        self::assertStringNotContainsString('db.internal', $body);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_the_version_is_withheld_when_no_probe_token_is_configured(): void
    {
        // The default a self-hosted instance inherits: presenting any header at
        // all must still reveal nothing.
        $response = $this->controller(probeToken: '')(self::probeRequest('anything'));

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_the_version_is_withheld_from_a_wrong_probe_token(): void
    {
        $response = $this->controller(probeToken: 'right-token')(self::probeRequest('wrong-token'));

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_the_version_is_withheld_when_the_header_is_absent(): void
    {
        $response = $this->controller(probeToken: 'right-token')(new Request());

        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $response->getContent());
    }

    public function test_a_correct_probe_token_gets_the_version(): void
    {
        $response = $this->controller(probeToken: 'right-token', version: '77bc23c')(
            self::probeRequest('right-token'),
        );

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","version":"77bc23c"}',
            (string) $response->getContent(),
        );
    }

    public function test_an_instance_built_outside_the_release_pipeline_reports_a_null_version(): void
    {
        // Present-and-null, not absent: the probe distinguishes "this build is
        // unidentified" from "your token was refused", which would otherwise
        // look identical.
        $response = $this->controller(probeToken: 'right-token', version: null)(
            self::probeRequest('right-token'),
        );

        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","version":null}',
            (string) $response->getContent(),
        );
    }

    private function controller(string $probeToken, ?string $version = null): ShowHealthController
    {
        $connection = $this->createStub(Connection::class);

        return new ShowHealthController(
            new CheckDatabaseHealthHandler($connection, new NullLogger()),
            self::buildIdentity($version),
            $probeToken,
        );
    }

    /** BuildIdentity reads a file, so a stated version needs one to read. */
    private static function buildIdentity(?string $version): BuildIdentity
    {
        $dir = sys_get_temp_dir().'/loupe-health-'.bin2hex(random_bytes(6));
        mkdir($dir.'/var', 0o777, true);
        if (null !== $version) {
            file_put_contents($dir.'/var/build-version', $version);
        }

        return new BuildIdentity($dir);
    }

    private static function probeRequest(string $token): Request
    {
        return new Request(server: ['HTTP_X_PROBE_TOKEN' => $token]);
    }
}
