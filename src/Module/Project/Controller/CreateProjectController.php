<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Command\ListProjectsCommand;
use App\Module\Project\Command\ListProjectsHandler;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/projects',
    name: 'app_project_create',
    methods: ['POST'],
)]
class CreateProjectController extends AppController
{
    public function __construct(
        private readonly CreateProjectHandler $createProjectHandler,
        private readonly ListProjectsHandler $listProjects,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $data = new CreateProjectRequest();
        $form = $this->createForm(CreateProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $name = trim($data->name ?? '');
            if ('' === $name) {
                throw new \LogicException('name required after validation');
            }

            try {
                ($this->createProjectHandler)(new CreateProjectCommand(
                    owner: $user,
                    name: $name,
                    domain: trim($data->domain ?? '') ?: null,
                ));

                return $this->redirectToRoute('app_projects');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        $page = max(1, $request->query->getInt('page', 1));
        $view = ($this->listProjects)(new ListProjectsCommand($user, $page));

        return $this->renderFormResponse('@Project/list_projects.html.twig', $form, [
            'items' => $view->items,
            'page' => $page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
        ]);
    }
}
