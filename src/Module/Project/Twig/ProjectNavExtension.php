<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\CurrentProjectProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ProjectNavExtension extends AbstractExtension
{
    /** How many the panel shows before deferring to its own see-all link. */
    private const int SWITCHER_LIMIT = 8;

    public function __construct(
        private readonly CurrentProjectProvider $currentProjectProvider,
        private readonly ProjectRepository $projects,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_project', $this->currentProject(...)),
            new TwigFunction('switchable_projects', $this->switchableProjects(...)),
        ];
    }

    public function currentProject(): ?Project
    {
        return $this->currentProjectProvider->current();
    }

    /** @return list<Project> */
    public function switchableProjects(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $projects = $this->projects->findNewestByOwner($user, self::SWITCHER_LIMIT);
        $current = $this->currentProject();
        if (null === $current) {
            return $projects;
        }

        foreach ($projects as $project) {
            if ($project->id?->equals($current->id)) {
                return $projects;
            }
        }

        return [...\array_slice($projects, 0, self::SWITCHER_LIMIT - 1), $current];
    }
}
