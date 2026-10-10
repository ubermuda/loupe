<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/** Why a version has no list of new passages. */
enum NewTextReason: string
{
    case NoPreviousVersion = 'no-previous-version';
    case DiffRefused = 'diff-refused';
}
