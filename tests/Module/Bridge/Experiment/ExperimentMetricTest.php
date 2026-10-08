<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\ExperimentMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExperimentMetricTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function formats(): iterable
    {
        yield 'money' => ['cost', 'currency'];
        yield 'ratio' => ['merge-rate', 'percent'];
        yield 'tokens' => ['output-tokens', 'integer'];
        yield 'run duration' => ['duration', 'minutes'];
        yield 'hours to merge' => ['hours-to-merge', 'decimal'];
        yield 'count' => ['fix-rounds', 'decimal'];
        yield 'fix reason' => ['conflict', 'decimal'];
    }

    #[DataProvider('formats')]
    public function test_each_row_takes_a_format(string $key, string $format): void
    {
        self::assertSame($format, new ExperimentMetric($key, [], false)->format());
    }
}
