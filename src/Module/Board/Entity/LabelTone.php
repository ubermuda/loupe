<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** The colour of a card type or column label. Each case has an .lp-tag modifier. */
enum LabelTone: string
{
    case Neutral = 'neutral';
    case Lime = 'lime';
    case Purple = 'purple';
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
}
