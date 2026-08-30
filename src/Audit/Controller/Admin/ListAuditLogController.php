<?php

declare(strict_types=1);

namespace App\Audit\Controller\Admin;

use App\Audit\Command\Admin\ListAuditLogCommand;
use App\Audit\Command\Admin\ListAuditLogHandler;
use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/audit-log',
    name: 'app_admin_audit_log_list',
    methods: ['GET'],
)]
final class ListAuditLogController extends AppController
{
    public function __construct(
        private readonly ListAuditLogHandler $listAuditLog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest(
            $request,
            ListAuditLogHandler::ALLOWED_SORTS,
            'occurredAt',
        );

        $view = ($this->listAuditLog)(new ListAuditLogCommand(
            page: $listRequest->page,
            dir: $listRequest->dir,
            actor: $request->query->getString('q') ?: null,
            operation: $request->query->getString('operation') ?: null,
            channel: $request->query->getString('channel') ?: null,
            from: $request->query->getString('from') ?: null,
            to: $request->query->getString('to') ?: null,
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute(
                'app_admin_audit_log_list',
                [...$request->query->all(), 'page' => $view->clampedPage],
            );
        }

        return $this->render('@Audit/admin/list_audit_log.html.twig', [
            'rows' => $view->rows,
            'channels' => $view->channels,
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
