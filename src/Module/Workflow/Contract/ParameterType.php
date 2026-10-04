<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum ParameterType: string
{
    case String = 'string';
    case Int = 'int';
    case Slot = 'slot';
}
