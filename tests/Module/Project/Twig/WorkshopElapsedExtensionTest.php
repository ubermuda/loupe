<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Twig;

use App\Module\Project\Twig\WorkshopElapsedExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WorkshopElapsedExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function elapsed(): iterable
    {
        yield 'a time in the future clamps to zero' => ['2026-09-28 12:05:00', 'minutes:0'];
        yield 'under a minute' => ['2026-09-28 11:59:01', 'minutes:0'];
        yield 'one minute' => ['2026-09-28 11:59:00', 'minutes:1'];
        yield 'the last minute before an hour' => ['2026-09-28 11:00:01', 'minutes:59'];
        yield 'one hour' => ['2026-09-28 11:00:00', 'hours:1'];
        yield 'the last hour before a day' => ['2026-09-27 12:00:01', 'hours:23'];
        yield 'one day' => ['2026-09-27 12:00:00', 'days:1'];
        yield 'many days' => ['2026-09-18 11:00:00', 'days:10'];
    }

    #[DataProvider('elapsed')]
    public function test_the_elapsed_time_uses_the_largest_whole_unit(string $since, string $expected): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters): string => substr($id, (int) strrpos($id, '.') + 1).':'.$parameters['%count%'],
        );
        $extension = new WorkshopElapsedExtension(new MockClock('2026-09-28 12:00:00'), $translator);

        self::assertSame($expected, $extension->elapsed(new \DateTimeImmutable($since)));
    }
}
