<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Expression\Expression;

final readonly class ActionCall
{
    /**
     * @param string                    $key     the key of the action
     * @param array<string, int|string> $params  the action parameters, without the until expression of a pause
     * @param ?Expression               $until   the release condition of a pause, and null for any other action
     * @param list<string>              $checks  what the work of a request needs from the project
     * @param ?Expression               $refill  the condition under which a request with a limit starts its count again, or null for none
     * @param list<AskOption>           $options the options of an ask, in template order, and empty for any other action
     * @param ?string                   $from    the slot the action acts from, when its `from` parameter names one, and null for any slot
     */
    public function __construct(
        public string $key,
        public array $params,
        public ?Expression $until = null,
        public array $checks = [],
        public ?Expression $refill = null,
        public array $options = [],
        public ActionTraits $traits = new ActionTraits(),
        public ?string $from = null,
    ) {
    }
}
