<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BuildIdentity;
use App\Service\UpdateCheck;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class UpdateCheckTest extends TestCase
{
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/update-check-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/var/build-version');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    private function build(
        HttpClientInterface $client,
        bool $flagEnabled = true,
        ?string $version = 'v1.4.0',
        string $sourceUrl = 'https://github.com/ubermuda/loupe',
    ): UpdateCheck {
        if (null !== $version) {
            file_put_contents($this->projectDir.'/var/build-version', $version);
        }

        /** @var FeatureFlagService&Stub $flags */
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($flagEnabled);

        return new UpdateCheck(
            new BuildIdentity($this->projectDir),
            $flags,
            $client,
            new ArrayAdapter(),
            new NullLogger(),
            $sourceUrl,
        );
    }

    private static function client(string $tag = 'v1.5.0', int $status = 200): MockHttpClient
    {
        return new MockHttpClient(
            new MockResponse((string) json_encode(['tag_name' => $tag]), ['http_code' => $status]),
            'https://api.github.com',
        );
    }

    public function test_a_newer_release_is_reported_as_available(): void
    {
        $status = $this->build(self::client('v1.5.0'))->status();

        self::assertNotNull($status);
        self::assertSame('v1.5.0', $status->latestVersion);
        self::assertTrue($status->isOutdated);
    }

    public function test_the_current_release_is_not_reported_as_outdated(): void
    {
        $status = $this->build(self::client('v1.4.0'))->status();

        self::assertNotNull($status);
        self::assertFalse($status->isOutdated);
    }

    public function test_the_flag_being_off_asks_github_nothing(): void
    {
        $client = self::client();

        self::assertNull($this->build($client, flagEnabled: false)->status());
        // The point of the flag is the request not happening, so asserting only
        // on the null return would pass even if the call had gone out.
        self::assertSame(0, $client->getRequestsCount());
    }

    public function test_a_build_with_no_version_asks_github_nothing(): void
    {
        $client = self::client();

        self::assertNull($this->build($client, version: null)->status());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function test_source_hosted_elsewhere_asks_github_nothing(): void
    {
        $client = self::client();

        self::assertNull($this->build($client, sourceUrl: 'https://gitlab.com/someone/loupe')->status());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function test_a_refused_request_degrades_instead_of_failing_the_page(): void
    {
        self::assertNull($this->build(self::client(status: 403))->status());
    }

    public function test_an_unreachable_github_degrades_instead_of_failing_the_page(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => throw new TransportException('no route to host'),
            'https://api.github.com',
        );

        self::assertNull($this->build($client)->status());
    }

    public function test_the_answer_is_cached_rather_than_fetched_per_page_view(): void
    {
        $client = self::client();
        $check = $this->build($client);

        $check->status();
        $check->status();

        self::assertSame(1, $client->getRequestsCount());
    }
}
