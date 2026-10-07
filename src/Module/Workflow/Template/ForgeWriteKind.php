<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

enum ForgeWriteKind: string
{
    case Merge = 'merge';
    case UpdateBranch = 'update-branch';
    case ChangeBase = 'change-base';
    case Comment = 'comment';
    case Draft = 'draft';
    case Ready = 'ready';
    case Close = 'close';
    case OpenEpic = 'open-epic';
}
