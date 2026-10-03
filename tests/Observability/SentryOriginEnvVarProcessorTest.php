<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryOriginEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SentryOriginEnvVarProcessorTest extends TestCase
{
    private const string CSP_SOURCE = '%env(default:app.default_csp_origin:sentry_origin:SENTRY_BROWSER_DSN)%';

    /** @return iterable<string, array{string, ?string}> */
    public static function dsns(): iterable
    {
        yield 'sentry.io' => ['https://key@o0.ingest.sentry.io/1', 'https://o0.ingest.sentry.io'];
        yield 'path prefix' => ['https://key@sentry.example/prefix/7', 'https://sentry.example'];
        yield 'custom port' => ['http://key@sentry.example:9000/1', 'http://sentry.example:9000'];
        yield 'default port' => ['https://key@sentry.example:443/1', 'https://sentry.example'];
        yield 'empty' => ['', null];
        yield 'off value' => ['false', null];
        yield 'malformed' => ['not-a-dsn', null];
    }

    #[DataProvider('dsns')]
    public function test_it_reads_the_origin_of_a_valid_dsn(string $dsn, ?string $origin): void
    {
        $value = new SentryOriginEnvVarProcessor()->getEnv('sentry_origin', 'SENTRY_BROWSER_DSN', static fn (): string => $dsn);

        self::assertSame($origin, $value);
    }

    public function test_the_csp_source_falls_back_to_self_without_a_dsn(): void
    {
        self::assertSame("'self'", $this->resolveCspSource(''));
    }

    public function test_the_csp_source_falls_back_to_self_for_a_malformed_dsn(): void
    {
        self::assertSame("'self'", $this->resolveCspSource('not-a-dsn'));
    }

    public function test_the_csp_source_is_the_ingest_origin_with_a_dsn(): void
    {
        self::assertSame('https://o0.ingest.sentry.io', $this->resolveCspSource('https://key@o0.ingest.sentry.io/1'));
    }

    private function resolveCspSource(string $dsn): mixed
    {
        $saved = [$_SERVER, $_ENV];
        $_SERVER['SENTRY_BROWSER_DSN'] = $_ENV['SENTRY_BROWSER_DSN'] = $dsn;

        try {
            $container = new ContainerBuilder();
            $container->setParameter('app.default_csp_origin', "'self'");
            $container->setParameter('probe', self::CSP_SOURCE);
            $container->setDefinition(
                SentryOriginEnvVarProcessor::class,
                new Definition(SentryOriginEnvVarProcessor::class)->addTag('container.env_var_processor'),
            );
            $container->compile(true);

            return $container->getParameter('probe');
        } finally {
            [$_SERVER, $_ENV] = $saved;
        }
    }
}
