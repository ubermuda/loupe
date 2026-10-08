<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;

final readonly class ListMetricsHandler
{
    public function __construct(
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,
    ) {
    }

    public function __invoke(ListMetricsCommand $command): ListMetricsView
    {
        return new ListMetricsView(Metric::standalone(), $this->workerRunBucketTimes->findBucketNamesOfProject($command->project));
    }
}
