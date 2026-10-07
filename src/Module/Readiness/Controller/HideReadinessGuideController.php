<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\HideReadinessGuideCommand;
use App\Module\Readiness\Command\HideReadinessGuideHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('readiness-guide-hide')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/readiness/guide/hide',
    name: 'app_readiness_guide_hide',
    methods: ['POST'],
)]
final class HideReadinessGuideController extends AppController
{
    public function __construct(
        private readonly HideReadinessGuideHandler $hideGuide,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        ($this->hideGuide)(new HideReadinessGuideCommand($project));

        return $this->redirectToRoute('app_project_workshop', ['id' => (string) $project->id]);
    }
}
