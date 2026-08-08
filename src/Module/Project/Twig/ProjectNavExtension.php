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

/**
 * Exposes the request's current project to templates — it drives the app-shell
 * switcher and scoped nav. Delegates to the resolver; no logic lives here.
 */
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

    /**
     * The projects the shell's switcher panel offers.
     *
     * Capped rather than complete: the panel is rendered into every
     * project-scoped page, opened or not, and it already ends in a link to the
     * full list for whatever does not fit.
     *
     * @return list<Project>
     */
    public function switchableProjects(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->projects->findNewestByOwner($user, self::SWITCHER_LIMIT) : [];
    }
}
