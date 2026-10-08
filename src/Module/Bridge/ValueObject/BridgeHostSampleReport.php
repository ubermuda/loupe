<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** One reading of the machine a bridge runs on, as the bridge reports it. */
final readonly class BridgeHostSampleReport
{
    /** @param list<float> $cpuPct */
    public function __construct(
        public \DateTimeImmutable $sampledAt,
        public array $cpuPct,
        public int $memUsed,
        public int $memTotal,
        public int $swapUsed,
        public ?float $batteryPct,
        public ?bool $onAc,
    ) {
    }
}
