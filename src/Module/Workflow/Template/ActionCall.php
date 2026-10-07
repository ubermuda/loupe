<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Expression\Expression;

final readonly class ActionCall
{
    /**
     * @param array<string, int|string> $params the action parameters, without the until expression of a pause
     * @param ?Expression               $until  the release condition of a pause, and null for any other action
     * @param list<string>              $checks what the work of a request needs from the project
     * @param ?Expression               $refill the condition under which a request with a limit starts its count again, or null for none
     */
    public function __construct(
        public ActionType $type,
        public array $params,
        public ?Expression $until = null,
        public array $checks = [],
        public ?Expression $refill = null,
    ) {
    }
}
