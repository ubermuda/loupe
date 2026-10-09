<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the engine needs to know about an action beyond its parameters. */
final readonly class ActionTraits
{
    public function __construct(
        /** A fire of the action uses one request of the work limit. */
        public bool $countsTowardLimit = false,
        /** The facts of the card change when the action is done, so the rules after it read them again. */
        public bool $refreshesFacts = false,
        /** The action moves the card, so the rules after it wait for the next pass. */
        public bool $endsPass = false,
        /** The action may run inside the option of an ask. */
        public bool $option = false,
    ) {
    }
}
