<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Service\WizardState;

/**
 * What every first-run wizard page must know before it renders: whether the
 * user has already finished, and which project the step describes. One read
 * serves all four steps, so a step cannot answer either question differently
 * from its neighbours.
 */
final readonly class ShowWizardHandler
{
    public function __construct(
        private WizardState $wizardState,
    ) {
    }

    public function __invoke(ShowWizardCommand $command): ShowWizardView
    {
        $completed = $this->wizardState->isCompleted($command->user);

        return new ShowWizardView(
            completed: $completed,
            project: $completed ? null : $this->wizardState->firstProject($command->user),
        );
    }
}
