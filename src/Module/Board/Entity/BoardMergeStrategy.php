<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

enum BoardMergeStrategy: string
{
    case Worker = 'worker';
    case Off = 'off';
}
