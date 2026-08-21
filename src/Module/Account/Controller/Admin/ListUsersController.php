<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\ListUsersCommand;
use App\Module\Account\Command\Admin\ListUsersHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/users',
    name: 'app_admin_users_list',
    methods: ['GET'],
)]
final class ListUsersController extends AppController
{
    public function __construct(
        private readonly ListUsersHandler $listUsers,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest(
            $request,
            ListUsersHandler::ALLOWED_SORTS,
            'createdAt',
        );

        $view = ($this->listUsers)(new ListUsersCommand(
            page: $listRequest->page,
            sort: $listRequest->sort,
            dir: $listRequest->dir,
            query: $request->query->getString('q') ?: null,
            verified: $request->query->getString('verified') ?: null,
            state: $request->query->getString('state') ?: null,
            role: $request->query->getString('role') ?: null,
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute(
                'app_admin_users_list',
                [...$request->query->all(), 'page' => $view->clampedPage],
            );
        }

        return $this->render('@Account/admin/users/list_users.html.twig', [
            'users' => $view->users,
            'total' => $view->total,
            'page' => $listRequest->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
            'filters' => $view->filters,
        ]);
    }
}
