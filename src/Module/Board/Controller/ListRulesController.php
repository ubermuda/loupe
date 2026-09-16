<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListRulesCommand;
use App\Module\Board\Command\ListRulesHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/rules',
    name: 'app_project_rules',
    methods: ['GET'],
)]
final class ListRulesController extends AppController
{
    public function __construct(
        private readonly ListRulesHandler $listRules,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->listRules)(new ListRulesCommand($project));

        return $this->render('@Board/list_rules.html.twig', [
            'project' => $view->project,
            'rules' => $view->rules,
            'liveCount' => $view->liveCount,
        ]);
    }
}
