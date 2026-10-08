<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\StartGitHubConnectCommand;
use App\Module\GitHub\Command\StartGitHubConnectHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the signed-in person to GitHub to connect their account. It touches
 * only the person's own connection, so no voter is needed. The widget opens it
 * in a popup and names its project and origin in the query.
 */
#[Route(
    '/account/github/connect',
    name: 'app_github_user_connect',
    methods: ['GET'],
)]
final class StartGitHubConnectController extends AppController
{
    public function __construct(
        private readonly StartGitHubConnectHandler $startConnect,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            return $this->redirect(($this->startConnect)(new StartGitHubConnectCommand(
                $request->query->has('project') ? $request->query->getString('project') : null,
                $request->query->has('origin') ? $request->query->getString('origin') : null,
                $this->generateUrl('app_github_user_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            )));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_account_connected_apps');
        }
    }
}
