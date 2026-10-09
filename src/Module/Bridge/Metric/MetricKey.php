<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Service\BucketRule;

/** A metric as an agent or a URL names it: `cost`, or `bucket-time:<name>` for the time in one bucket. */
final readonly class MetricKey
{
    public function __construct(
        public Metric $metric,
        public ?string $bucketName = null,
    ) {
        if ((Metric::BucketTime === $metric) !== (null !== $bucketName)) {
            throw new \InvalidArgumentException(\sprintf('The metric %s takes a bucket name only when it is %s.', $metric->value, Metric::BucketTime->value));
        }
        if (null !== $bucketName && 1 !== preg_match(BucketRule::NAME_PATTERN, $bucketName)) {
            throw new \InvalidArgumentException(\sprintf('The bucket name "%s" is not 1 to 64 characters of a-z, 0-9, "_" and "-".', $bucketName));
        }
    }

    public static function tryParse(string $key): ?self
    {
        $prefix = Metric::BucketTime->value.':';
        if (str_starts_with($key, $prefix)) {
            $name = substr($key, \strlen($prefix));

            return 1 === preg_match(BucketRule::NAME_PATTERN, $name) ? new self(Metric::BucketTime, $name) : null;
        }

        $metric = Metric::tryFrom($key);

        return null === $metric || Metric::BucketTime === $metric ? null : new self($metric);
    }

    public function key(): string
    {
        return null === $this->bucketName ? $this->metric->value : $this->metric->value.':'.$this->bucketName;
    }
}
