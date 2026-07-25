<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'app_admin_dashboard')]
final class ShowAdminDashboardController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Admin/show_admin_dashboard.html.twig');
    }
}
