<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;

/**
 * The eligibility guard every first-run wizard endpoint checks before doing
 * anything else: has the user already finished (or skipped) the wizard, and
 * do they already own a project.
 */
final readonly class WizardState
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function isCompleted(User $user): bool
    {
        return null !== $user->wizardCompletedAt;
    }

    public function firstProject(User $user): ?Project
    {
        return $this->projects->findOldestByOwner($user);
    }
}
