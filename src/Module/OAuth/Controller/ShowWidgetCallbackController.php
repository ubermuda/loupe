<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\OAuth\Command\ShowWidgetCallbackCommand;
use App\Module\OAuth\Command\ShowWidgetCallbackHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The redirect URI of the site-review widget's OAuth client. It runs in the
 * sign-in popup and hands the answer to the widget that opened it.
 */
#[Route(
    '/oauth/widget/callback',
    name: 'oauth_widget_callback',
    methods: ['GET'],
)]
final class ShowWidgetCallbackController extends AppController
{
    public function __construct(
        private readonly ShowWidgetCallbackHandler $showCallback,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $view = ($this->showCallback)(new ShowWidgetCallbackCommand(
            state: $request->query->getString('state'),
            code: $request->query->getString('code'),
            error: $request->query->getString('error'),
        ));

        $response = $this->render('@OAuth/show_widget_callback.html.twig', ['view' => $view]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
