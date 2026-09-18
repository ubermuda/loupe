<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Search\Command\SearchOwnedProjectsCommand;
use App\Search\Command\SearchOwnedProjectsHandler;
use App\Search\Form\SearchFormType;
use App\Search\Form\SearchRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/search',
    name: 'app_search_owned_projects',
    methods: ['GET'],
)]
final class SearchOwnedProjectsController extends AppController
{
    public function __construct(
        private readonly SearchOwnedProjectsHandler $search,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $owner = $this->getUser();
        if (!$owner instanceof User) {
            throw new \LogicException('Owned-project search requires the authenticated User supplied by the ROLE_USER firewall rule.');
        }
        $data = new SearchRequest();
        $form = $this->createForm(SearchFormType::class, $data);
        $form->handleRequest($request);
        $view = null;
        if (!$form->isSubmitted() || $form->isValid()) {
            $view = ($this->search)(new SearchOwnedProjectsCommand($owner, $data->query, $data->page));
        }
        $template = 'project-search-results' === $request->headers->get('Turbo-Frame')
            ? 'search/search_owned_projects_frame.html.twig'
            : 'search/search_owned_projects.html.twig';
        $response = $this->renderFormResponse($template, $form, ['project' => null, 'view' => $view])
            ->setStatusCode(null === $view ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
