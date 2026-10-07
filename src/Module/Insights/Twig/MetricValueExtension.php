<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Insights\View\MetricValueFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MetricValueExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('metric_value', $this->metricValue(...))];
    }

    /** Symfony sets the default locale from the request, as format_currency reads it. */
    public function metricValue(int|float|null $value, Metric $metric, ?MetricStatistic $statistic = null, ?int $moneyDecimals = null): string
    {
        return new MetricValueFormatter(\Locale::getDefault())->format($value, $metric, $statistic, $moneyDecimals)
            ?? $this->translator->trans('analytics.metrics.unknown');
    }
}
