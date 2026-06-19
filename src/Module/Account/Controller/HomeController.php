<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
class HomeController extends AppController
{
    public function __invoke(): Response
    {
        // The documents dashboard is the app's home for an authenticated user
        // (the ^/ firewall guarantees authentication before this runs).
        return $this->redirectToRoute('app_documents');
    }
}
