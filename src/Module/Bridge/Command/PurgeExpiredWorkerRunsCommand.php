<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** Sweeps every run row older than the retention window. It carries no data. */
final readonly class PurgeExpiredWorkerRunsCommand
{
}
