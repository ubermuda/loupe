<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;

final readonly class ListMetricsHandler
{
    public function __invoke(ListMetricsCommand $command): ListMetricsView
    {
        return new ListMetricsView(Metric::cases());
    }
}
