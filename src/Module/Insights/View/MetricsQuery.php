<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The controls of the Metrics page. routeParams() leaves out a control at its
 * default. The GET form still submits every control.
 */
final readonly class MetricsQuery
{
    public function __construct(
        public MetricUnit $unit = MetricUnit::Card,
        public Metric $metric = Metric::Cost,
        public MetricStatistic $statistic = MetricStatistic::Median,
        public MetricGroup $group = MetricGroup::None,
        public MetricRange $range = MetricRange::NinetyDays,
        public MetricBucket $bucket = MetricBucket::Week,
    ) {
    }

    /**
     * An unknown value takes the default. A value the metric does not allow
     * takes the default when the metric allows it, or else the first it allows.
     *
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        $requested = Metric::tryFrom($query->getString('metric'));
        $metric = null !== $requested && \in_array($requested, Metric::standalone(), true) ? $requested : Metric::Cost;

        return new self(
            unit: self::allowed(MetricUnit::tryFrom($query->getString('unit')) ?? MetricUnit::Card, $metric->units()),
            metric: $metric,
            statistic: self::allowed(MetricStatistic::tryFrom($query->getString('statistic')) ?? MetricStatistic::Median, $metric->statistics(), MetricStatistic::Median),
            group: self::allowed(MetricGroup::tryFrom($query->getString('group')) ?? MetricGroup::None, $metric->groups(), MetricGroup::None),
            range: MetricRange::tryFrom($query->getString('range')) ?? MetricRange::NinetyDays,
            bucket: MetricBucket::tryFrom($query->getString('bucket')) ?? MetricBucket::Week,
        );
    }

    /** @return array{unit?: string, metric?: string, statistic?: string, group?: string, range?: string, bucket?: string} */
    public function routeParams(): array
    {
        $params = [];
        if (MetricUnit::Card !== $this->unit) {
            $params['unit'] = $this->unit->value;
        }
        if (Metric::Cost !== $this->metric) {
            $params['metric'] = $this->metric->value;
        }
        if (MetricStatistic::Median !== $this->statistic) {
            $params['statistic'] = $this->statistic->value;
        }
        if (MetricGroup::None !== $this->group) {
            $params['group'] = $this->group->value;
        }
        if (MetricRange::NinetyDays !== $this->range) {
            $params['range'] = $this->range->value;
        }
        if (MetricBucket::Week !== $this->bucket) {
            $params['bucket'] = $this->bucket->value;
        }

        return $params;
    }

    /**
     * @template T of \UnitEnum
     *
     * @param T       $requested
     * @param list<T> $allowed
     * @param ?T      $preferred
     *
     * @return T
     */
    private static function allowed(\UnitEnum $requested, array $allowed, ?\UnitEnum $preferred = null): \UnitEnum
    {
        if (\in_array($requested, $allowed, true)) {
            return $requested;
        }
        if (null !== $preferred && \in_array($preferred, $allowed, true)) {
            return $preferred;
        }

        return $allowed[0] ?? throw new \LogicException('A metric allows at least one value of each control.');
    }
}
