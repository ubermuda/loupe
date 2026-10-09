<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Twig\CardStateExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardStateExtensionTest extends KernelTestCase
{
    private const string NOW = '2026-10-09 14:02:00';

    /** @return iterable<string, array{string, string, string}> */
    public static function spans(): iterable
    {
        yield 'seconds' => ['2026-10-09 14:01:30', '14:01, just now', 'Stuck for under a minute'];
        yield 'one minute' => ['2026-10-09 14:01:00', '14:01, 1 minute ago', 'Stuck for 1 min'];
        yield 'minutes' => ['2026-10-09 13:20:00', '13:20, 42 minutes ago', 'Stuck for 42 min'];
        yield 'hours' => ['2026-10-09 12:00:00', '12:00, 2 hours ago', 'Stuck for 2 h'];
        yield 'another day' => ['2026-10-07 09:15:00', 'Oct 7, 09:15, 2 days ago', 'Stuck for 2 d'];
    }

    #[DataProvider('spans')]
    public function test_a_start_time_reads_as_a_clock_time_and_an_age(string $since, string $time, string $chip): void
    {
        $extension = $this->extension();

        self::assertSame($time, new Crawler((string) $extension->text('board.card_state.since_value', new \DateTimeImmutable($since)))->text());
        self::assertSame($chip, new Crawler((string) $extension->text('board.card_state.held_for', new \DateTimeImmutable($since), 'duration', ['%state%' => 'Stuck']))->text());
    }

    public function test_the_age_sits_in_a_span_that_the_controller_keeps_current(): void
    {
        $html = (string) $this->extension()->text('board.card_state.since.working', new \DateTimeImmutable('2026-10-09 12:00:00'));
        $age = new Crawler($html)->filter('span[data-controller="state-age"]');

        self::assertSame('2 hours ago', $age->text());
        self::assertSame((string) new \DateTimeImmutable('2026-10-09 12:00:00')->getTimestamp(), $age->attr('data-state-age-since-value'));
        self::assertSame((string) new \DateTimeImmutable(self::NOW)->getTimestamp(), $age->attr('data-state-age-now-value'));
        self::assertSame('ago', $age->attr('data-state-age-mode-value'));
        self::assertStringStartsWith('Started 12:00, <span', $html);
    }

    public function test_a_parameter_is_escaped(): void
    {
        $html = (string) $this->extension()->text('board.card_state.held_for', new \DateTimeImmutable(self::NOW), 'duration', ['%state%' => '<b>x</b>']);

        self::assertStringStartsWith('&lt;b&gt;x&lt;/b&gt; for <span', $html);
    }

    public function test_the_age_texts_carry_every_form_with_a_literal_count(): void
    {
        $texts = $this->extension()->ageTexts();

        self::assertSame(['now', 'minute', 'minutes', 'hour', 'hours', 'day', 'days'], array_keys($texts['ago']));
        self::assertSame('%count% hours ago', $texts['ago']['hours']);
        self::assertSame('%count% min', $texts['duration']['minutes']);
    }

    private function extension(): CardStateExtension
    {
        self::bootKernel();

        return new CardStateExtension(static::getContainer()->get(TranslatorInterface::class), new MockClock(self::NOW));
    }
}
