<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

enum ActionType: string
{
    case Move = 'move';
    case Request = 'request';
    // The value is the action key of the template format, which every stored template copy also uses.
    case ForgeWrite = 'forge_write'; // @phpstan-ignore enum.notKebabCase
    case Pause = 'pause';
    case Release = 'release';
}
