<?php

declare(strict_types=1);

namespace App\Module\Legal\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/terms',
    name: 'app_terms',
    methods: ['GET'],
)]
final class ShowTermsController extends AppController
{
    public function __invoke(Request $request): Response
    {
        return $this->render('@Legal/show_terms.'.$request->getLocale().'.html.twig');
    }
}
