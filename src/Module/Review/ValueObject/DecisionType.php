<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * How many options one decision block accepts.
 *
 * A block rendered before multi-choice existed carries no type attribute, so
 * every reader falls back to Single. That fallback is what keeps a stored
 * answer readable after this shipped.
 */
enum DecisionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
