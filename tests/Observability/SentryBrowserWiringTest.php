<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryOriginEnvVarProcessor;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class SentryBrowserWiringTest extends KernelTestCase
{
    private const string BROWSER_DSN = 'https://key@o0.ingest.example/1';

    /** @var array<string, array{mixed, mixed}> */
    private array $savedEnv = [];

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        $this->savedEnv = [];
    }

    public function test_an_empty_browser_dsn_renders_no_sdk_options(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = $twig->createTemplate('{{ sentry_browser() is null ? "off" : "on" }}')->render();

        self::assertSame('off', $rendered);
    }

    public function test_the_sentry_origin_processor_is_registered(): void
    {
        self::bootKernel();
        $locator = self::getContainer()->get('container.env_var_processors_locator');
        self::assertInstanceOf(ContainerInterface::class, $locator);

        self::assertInstanceOf(SentryOriginEnvVarProcessor::class, $locator->get('sentry_origin'));
    }

    public function test_a_browser_dsn_renders_the_page_settings_and_the_sdk_script(): void
    {
        $this->setEnv('SENTRY_BROWSER_DSN', self::BROWSER_DSN);
        $this->setEnv('SENTRY_DSN', '');

        $page = $this->renderPartial();

        self::assertSame('app_login', $page->filter('meta[name="loupe-route"]')->attr('content'));
        self::assertSame(self::BROWSER_DSN, $page->filter('meta[name="sentry-browser-dsn"]')->attr('content'));
        self::assertSame('1', $page->filter('meta[name="sentry-browser-traces-sample-rate"]')->attr('content'));
        self::assertSame('test', $page->filter('meta[name="sentry-environment"]')->attr('content'));
        self::assertStringContainsString('sentry/bundle.tracing', (string) $page->filter('script[defer]')->attr('src'));
        self::assertCount(0, $page->filter('meta[name="sentry-trace"]'));
        self::assertCount(0, $page->filter('meta[name="baggage"]'));
    }

    /** A valid server DSN starts the PHP SDK, which installs error handlers, so the Sentry functions are stubs here. */
    public function test_the_trace_meta_tags_render_only_when_the_page_continues_the_server_trace(): void
    {
        $tags = static fn (bool $continueTrace): Crawler => self::renderWithStubs($continueTrace);

        self::assertCount(1, $tags(true)->filter('meta[name="sentry-trace"]'));
        self::assertCount(1, $tags(true)->filter('meta[name="baggage"]'));
        self::assertCount(0, $tags(false)->filter('meta[name="sentry-trace"]'));
        self::assertCount(0, $tags(false)->filter('meta[name="baggage"]'));
        self::assertCount(1, $tags(false)->filter('meta[name="sentry-browser-dsn"]'));
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function renderPartial(): Crawler
    {
        self::bootKernel();
        $container = self::getContainer();
        $requestStack = $container->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('/login');
        $request->attributes->set('_route', 'app_login');
        $requestStack->push($request);
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler('<html><head>'.$twig->render('_sentry_browser.html.twig').'</head></html>');
    }

    private static function renderWithStubs(bool $continueTrace): Crawler
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2).'/templates'));
        $options = ['dsn' => self::BROWSER_DSN, 'tracesSampleRate' => 1.0, 'release' => null, 'environment' => 'test', 'continueTrace' => $continueTrace];
        $twig->addFunction(new TwigFunction('sentry_browser', static fn (): array => $options));
        $twig->addFunction(new TwigFunction('sentry_trace_meta', static fn (): string => '<meta name="sentry-trace" content="t">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('sentry_baggage_meta', static fn (): string => '<meta name="baggage" content="b">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/'.$path));
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/login'));
        $app = new AppVariable();
        $app->setRequestStack($requestStack);
        $twig->addGlobal('app', $app);

        return new Crawler('<html><head>'.$twig->render('_sentry_browser.html.twig').'</head></html>');
    }
}
