<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * The colour of a card type or column label. Each case has an .lp-tag and an
 * .lp-tone-dot colour. Card types use the first six; a column may use any.
 */
enum LabelTone: string
{
    case Neutral = 'neutral';
    case Lime = 'lime';
    case Purple = 'purple';
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
    case Teal = 'teal';
    case Sky = 'sky';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Pink = 'pink';
    case Orange = 'orange';
}
