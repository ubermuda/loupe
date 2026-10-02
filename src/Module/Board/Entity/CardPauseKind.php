<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What paused a card: a rule that pauses, too many retries, or work that hit its limit or its timeout. */
enum CardPauseKind: string
{
    case Rule = 'rule';

    case Retries = 'retries';

    case WorkLimit = 'work-limit';

    case WorkTimeout = 'work-timeout';
}
