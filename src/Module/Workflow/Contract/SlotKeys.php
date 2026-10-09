<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The slot keys that name a column by its flag, not by a link. */
final class SlotKeys
{
    public const string BACKLOG = '@backlog';

    public const string TERMINAL = '@terminal';
}
