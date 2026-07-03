<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\CurrentProjectProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the request's current project to templates — it drives the app-shell
 * switcher and scoped nav. Delegates to the resolver; no logic lives here.
 */
final class ProjectNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentProjectProvider $currentProjectProvider,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_project', $this->currentProject(...)),
        ];
    }

    public function currentProject(): ?Project
    {
        return $this->currentProjectProvider->current();
    }
}
