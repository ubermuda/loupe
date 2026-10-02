<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryOriginEnvVarProcessor;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class SentryBrowserWiringTest extends KernelTestCase
{
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
}
