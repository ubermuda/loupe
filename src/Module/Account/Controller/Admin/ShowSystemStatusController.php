<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\CheckSystemStatusHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The install wizard's status page, kept reachable for the rest of the
 * instance's life: mail credentials expire, hubs get moved, and a worker that
 * stops is invisible until someone notices missing email.
 *
 * ROLE_ADMIN because the page names which infrastructure is configured and
 * which is not — a map of an instance's weak points.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/status',
    name: 'app_admin_system_status',
    methods: ['GET'],
)]
final class ShowSystemStatusController extends AppController
{
    public function __construct(
        private readonly CheckSystemStatusHandler $checkSystemStatus,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@Account/admin/show_system_status.html.twig', [
            'status' => ($this->checkSystemStatus)(),
        ]);
    }
}
