<?php

declare(strict_types=1);

namespace App\Module\Legal\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/ai-policy',
    name: 'app_ai_policy',
    methods: ['GET'],
)]
final class ShowAiPolicyController extends AppController
{
    public function __invoke(Request $request): Response
    {
        return $this->render('@Legal/show_ai_policy.'.$request->getLocale().'.html.twig');
    }
}
