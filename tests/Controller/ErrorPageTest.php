<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Renders the template directly rather than through a request: the test
 * client's error renderer dumps the exception whatever the debug flag says, so
 * an HTTP-level test would assert on Symfony's output instead of ours.
 */
final class ErrorPageTest extends KernelTestCase
{
    private function render(int $statusCode, string $statusText): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->render(
            '@Twig/Exception/error.html.twig',
            ['status_code' => $statusCode, 'status_text' => $statusText],
        ));
    }

    /** @return list<array{int, string, string}> */
    public static function statuses(): array
    {
        return [
            [403, 'Forbidden', 'Not yours to open'],
            [404, 'Not Found', 'Nothing here'],
            [500, 'Internal Server Error', 'Something broke'],
            // No copy of its own, so it must land on the generic wording rather
            // than print the untranslated key it builds the lookup from.
            [405, 'Method Not Allowed', 'Something went wrong'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_each_status_renders_its_own_copy(int $statusCode, string $statusText, string $expectedTitle): void
    {
        $crawler = $this->render($statusCode, $statusText);

        self::assertSame((string) $statusCode, $crawler->filter('.lp-error__code')->text());
        self::assertSame($expectedTitle, $crawler->filter('.lp-error__title')->text());
        self::assertNotSame('', $crawler->filter('.lp-error__body')->text());
    }

    public function test_the_way_out_points_at_a_route_that_still_exists(): void
    {
        // The template renders while a failure is already being handled, so an
        // unresolvable path() here costs the branded page entirely — Symfony
        // catches the second failure and falls back to its own plain output.
        self::assertSame('/', $this->render(404, 'Not Found')->filter('.lp-error a')->attr('href'));
    }
}
