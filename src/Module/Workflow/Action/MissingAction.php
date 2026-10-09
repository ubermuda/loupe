<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;

/** Stands for an action this version does not know, so it does nothing. The parser refuses its key in a template, and its rule never fires. */
final readonly class MissingAction implements Action
{
    public const string KEY = 'missing-action';

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(option: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.missing', 'workflow.panel.action.missing');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        return ActionOutcome::done();
    }
}
