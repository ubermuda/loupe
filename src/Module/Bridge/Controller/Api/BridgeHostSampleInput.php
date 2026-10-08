<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use Symfony\Component\Validator\Constraints as Assert;

/** One reading of the machine a bridge runs on. */
final class BridgeHostSampleInput
{
    /** Far above the cores of one machine, and small enough to bound the JSON column. */
    public const int MAX_CORES = 1024;

    /**
     * @param list<int|float>|null $cpuPct     the use of each core, in percent
     * @param float|null           $batteryPct null on a machine with no battery
     * @param bool|null            $onAc       null when the machine does not say
     */
    public function __construct(
        #[Assert\NotNull]
        public ?\DateTimeImmutable $sampledAt = null,

        #[Assert\All([new Assert\Type(['int', 'float']), new Assert\Range(min: 0, max: 100)])]
        #[Assert\Count(max: self::MAX_CORES)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        public ?array $cpuPct = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $memUsed = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $memTotal = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $swapUsed = null,

        #[Assert\Range(min: 0, max: 100)]
        public ?float $batteryPct = null,
        public ?bool $onAc = null,
    ) {
    }

    public function report(): BridgeHostSampleReport
    {
        return new BridgeHostSampleReport(
            sampledAt: ($this->sampledAt ?? throw new \LogicException('sampledAt is required after validation.'))->setTimezone(new \DateTimeZone('UTC')),
            cpuPct: array_map(static fn (int|float $pct): float => (float) $pct, array_values($this->cpuPct ?? [])),
            memUsed: $this->memUsed ?? 0,
            memTotal: $this->memTotal ?? 0,
            swapUsed: $this->swapUsed ?? 0,
            batteryPct: $this->batteryPct,
            onAc: $this->onAc,
        );
    }
}
