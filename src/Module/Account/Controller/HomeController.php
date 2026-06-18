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
        return new Response('<html><body><p>Home (placeholder)</p></body></html>');
    }
}
