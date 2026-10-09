<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Twig\CardStateExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardStateExtensionTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function spans(): iterable
    {
        yield 'seconds' => ['2026-10-09 14:01:30', '14:01, just now', 'under a minute'];
        yield 'one minute' => ['2026-10-09 14:01:00', '14:01, 1 minute ago', '1 min'];
        yield 'minutes' => ['2026-10-09 13:20:00', '13:20, 42 minutes ago', '42 min'];
        yield 'hours' => ['2026-10-09 12:00:00', '12:00, 2 hours ago', '2 h'];
        yield 'another day' => ['2026-10-07 09:15:00', 'Oct 7, 09:15, 2 days ago', '2 d'];
    }

    #[DataProvider('spans')]
    public function test_a_start_time_reads_as_a_clock_time_and_an_age(string $since, string $time, string $duration): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $extension = new CardStateExtension($translator, new MockClock('2026-10-09 14:02:00'));

        self::assertSame($time, $extension->time(new \DateTimeImmutable($since)));
        self::assertSame($duration, $extension->duration(new \DateTimeImmutable($since)));
    }
}
