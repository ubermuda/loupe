<?php

declare(strict_types=1);

namespace App\Module\Legal\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/privacy',
    name: 'app_privacy',
    methods: ['GET'],
)]
final class ShowPrivacyController extends AppController
{
    public function __invoke(Request $request): Response
    {
        return $this->render('@Legal/show_privacy.'.$request->getLocale().'.html.twig');
    }
}
