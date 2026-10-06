<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/** A metric value as a person reads it. Null for an unknown value, which the template translates. */
final readonly class MetricValueFormatter
{
    public function __construct(
        private string $locale,
    ) {
    }

    /**
     * A count of rows reads as a number, whatever the type of its metric.
     *
     * @param ?MetricStatistic $statistic     null for the value of one row
     * @param ?int             $moneyDecimals the decimals of a dollar amount, two when null
     */
    public function format(int|float|null $value, Metric $metric, ?MetricStatistic $statistic = null, ?int $moneyDecimals = null): ?string
    {
        if (null === $value) {
            return null;
        }
        if (MetricStatistic::Count === $statistic) {
            return $this->number($value, \NumberFormatter::DECIMAL, 0, 0);
        }

        return match ($metric->valueType()) {
            MetricValueType::Money => $this->money($value, $moneyDecimals ?? self::moneyDecimals($value)),
            MetricValueType::Ratio => $this->number($value, \NumberFormatter::PERCENT, 1, 1),
            MetricValueType::Duration => Metric::HoursToMerge === $metric
                ? $this->number($value, \NumberFormatter::DECIMAL, 0, 1).' h'
                : self::milliseconds((int) round($value)),
            MetricValueType::Tokens, MetricValueType::Count, MetricValueType::Boolean => $this->number($value, \NumberFormatter::DECIMAL, 0, 1),
        };
    }

    /** A cost under half a cent keeps four decimals, so it never reads as free. */
    private static function moneyDecimals(int|float $value): int
    {
        return $value > 0 && $value < 0.005 ? 4 : 2;
    }

    private function money(int|float $value, int $decimals): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->formatCurrency((float) $value, 'USD');
    }

    private function number(int|float $value, int $style, int $minDecimals, int $maxDecimals): string
    {
        $formatter = new \NumberFormatter($this->locale, $style);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minDecimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return (string) $formatter->format($value);
    }

    private static function milliseconds(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds.' ms';
        }

        $seconds = intdiv($milliseconds + 500, 1000);
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min '.($seconds % 60).' s';
        }

        return intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min';
    }
}
