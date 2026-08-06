<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ListProjectsCommand;
use App\Module\Project\Command\ListProjectsHandler;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/projects',
    name: 'app_projects',
    methods: ['GET'],
)]
class ListProjectsController extends AppController
{
    public function __construct(
        private readonly ListProjectsHandler $listProjects,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $page = max(1, $request->query->getInt('page', 1));
        $view = ($this->listProjects)(new ListProjectsCommand($user, $page));

        if (null !== $view->clampedPage) {
            $this->logger->info('project.list.page_clamped', [
                'user' => (string) $user->id,
                'requestedPage' => $page,
                'clampedPage' => $view->clampedPage,
            ]);

            return $this->redirectToRoute('app_projects', ['page' => $view->clampedPage]);
        }

        $form = $this->createForm(CreateProjectFormType::class, new CreateProjectRequest());

        return $this->render('@Project/list_projects.html.twig', [
            'items' => $view->items,
            'form' => $form->createView(),
            'page' => $page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
        ]);
    }
}
