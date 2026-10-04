<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Template\ActionType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class Actions
{
    /** @var array<string, Action> */
    private array $byType;

    /** @param iterable<Action> $actions */
    public function __construct(
        #[AutowireIterator('app.workflow_action')]
        iterable $actions,
    ) {
        $byType = [];
        foreach ($actions as $action) {
            $type = $action::type()->value;
            if (isset($byType[$type])) {
                throw new \LogicException(\sprintf('Two workflow actions have the type "%s": %s and %s.', $type, $byType[$type]::class, $action::class));
            }
            $byType[$type] = $action;
        }
        $this->byType = $byType;
    }

    public function get(ActionType $type): Action
    {
        return $this->byType[$type->value] ?? throw new \LogicException(\sprintf('No workflow action has the type "%s".', $type->value));
    }
}
