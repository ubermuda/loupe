<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads which actions the rules of a project run on a condition. The engine implements it. */
interface RuleActions
{
    /**
     * The parameters of each action with this key, in each rule of the project that fires on the condition.
     * A condition under `not` or `any` does not make the rule fire on it, so only the top level counts.
     * The list is empty while the automation of the project is off, and for a project with no template.
     *
     * @return list<array<string, int|string>>
     */
    public function onCondition(Uuid $projectId, string $conditionKey, string $actionKey): array;
}
