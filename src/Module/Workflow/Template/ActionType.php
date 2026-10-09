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
    case Ask = 'ask';
    case LinkDocument = 'link-document';
    case Detach = 'detach';
    /** Stands for an action this version does not know. The parser refuses the value in a template. */
    case Missing = 'missing-action';
}
