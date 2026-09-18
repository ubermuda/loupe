<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ListRulesCommand;
use App\Module\Board\Command\ListRulesHandler;
use App\Module\Board\Form\SearchRulesFormType;
use App\Module\Board\Form\SearchRulesRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
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

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new SearchRulesRequest();
        $form = $this->createForm(SearchRulesFormType::class, $data);
        $form->handleRequest($request);
        $search = $form->isSubmitted() && $form->isValid() ? ($data->search ?? '') : '';
        $view = ($this->listRules)(new ListRulesCommand($project, $search));

        return $this->renderFormResponse('@Board/list_rules.html.twig', $form, [
            'project' => $view->project,
            'rules' => $view->rules,
            'liveCount' => $view->liveCount,
            'search' => $view->search,
            'searchForm' => $form->createView(),
        ])->setStatusCode($form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
