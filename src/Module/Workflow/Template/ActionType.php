<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

enum ActionType: string
{
    case Move = 'move';
    case Request = 'request';
    case ForgeWrite = 'forge-write';
    case Pause = 'pause';
    case Release = 'release';
    case Evaluate = 'evaluate';
}
