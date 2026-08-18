<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Controller\Admin;

use App\Controller\AppController;
use App\Module\Diagnostics\Command\RunDiagnosticsHandler;
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
    name: 'app_admin_diagnostics',
    methods: ['GET'],
)]
final class ShowDiagnosticsController extends AppController
{
    public function __construct(
        private readonly RunDiagnosticsHandler $runDiagnostics,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@Diagnostics/admin/show_diagnostics.html.twig', [
            'status' => ($this->runDiagnostics)(),
        ]);
    }
}
