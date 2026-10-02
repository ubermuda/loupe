<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryBrowserConfig;
use App\Service\BuildIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

final class SentryBrowserConfigTest extends TestCase
{
    private const string DSN = 'https://key@o0.ingest.example/1';

    /** @return iterable<string, array{?string}> */
    public static function noBrowserSdk(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'off string' => ['(null)'];
        yield 'malformed' => ['not-a-dsn'];
    }

    #[DataProvider('noBrowserSdk')]
    public function test_an_off_or_malformed_dsn_loads_no_browser_sdk(?string $dsn): void
    {
        self::assertNull($this->config($dsn)->sentryBrowser());
    }

    public function test_a_valid_dsn_gives_the_browser_sdk_its_options(): void
    {
        $projectDir = sys_get_temp_dir().'/sentry-browser-config-'.bin2hex(random_bytes(4));
        mkdir($projectDir.'/var', recursive: true);
        file_put_contents($projectDir.'/var/build-version', "v1.2.3\n");

        $config = new SentryBrowserConfig(self::DSN, 0.25, new BuildIdentity($projectDir), 'prod');

        self::assertSame(
            ['dsn' => self::DSN, 'tracesSampleRate' => 0.25, 'release' => 'v1.2.3', 'environment' => 'prod'],
            $config->sentryBrowser(),
        );
    }

    public function test_a_build_with_no_version_has_no_release(): void
    {
        $options = $this->config(self::DSN)->sentryBrowser();

        self::assertNotNull($options);
        self::assertNull($options['release']);
    }

    public function test_the_template_calls_it_as_sentry_browser(): void
    {
        $names = array_map(static fn (TwigFunction $f): string => $f->getName(), $this->config(null)->getFunctions());

        self::assertSame(['sentry_browser'], $names);
    }

    private function config(?string $dsn): SentryBrowserConfig
    {
        return new SentryBrowserConfig($dsn, 1.0, new BuildIdentity(sys_get_temp_dir().'/no-such-project'), 'test');
    }
}
