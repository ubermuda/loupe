<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** Why the report leaves a card of the experiment out of its figures. The cases are in display order. */
enum LeftOutReason: string
{
    /** A run of the card moved it from a variant the rule no longer offered. */
    case Switched = 'switched';

    /** The runs of the card name more than one variant. */
    case Mixed = 'mixed';

    /** A run with no experiment worked the card in the same column before the experiment did. */
    case BeforeTest = 'before-test';

    /** The card history starts after the first experiment run of the card. */
    case NoHistory = 'no-history';
}
