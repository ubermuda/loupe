<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** An action that has a rule on its parameters as a whole, beyond the rule of each parameter. */
interface ChecksParameters
{
    /**
     * @param array<string, mixed> $params the parameters that passed their own rule
     *
     * @return list<string> one message for each broken rule
     */
    public static function check(array $params): array;
}
