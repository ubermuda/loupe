<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Search\Command\SearchProjectCommand;
use App\Search\Command\SearchProjectHandler;
use App\Search\Form\SearchFormType;
use App\Search\Form\SearchRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/search',
    name: 'app_project_search',
    methods: ['GET'],
)]
final class SearchProjectController extends AppController
{
    public function __construct(
        private readonly SearchProjectHandler $search,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new SearchRequest();
        $form = $this->createForm(SearchFormType::class, $data);
        $form->handleRequest($request);
        $view = null;
        if (!$form->isSubmitted() || $form->isValid()) {
            $view = ($this->search)(new SearchProjectCommand($project, $data->query, $data->page));
        }

        $template = 'project-search-results' === $request->headers->get('Turbo-Frame')
            ? 'search/search_project_frame.html.twig'
            : 'search/search_project.html.twig';
        $response = $this->renderFormResponse($template, $form, ['project' => $project, 'view' => $view])
            ->setStatusCode(null === $view ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
