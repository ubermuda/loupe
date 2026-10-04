<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** Opens again every claimed work request whose lease ran out. It carries no data. */
final readonly class ReopenLapsedWorkRequestsCommand
{
}
