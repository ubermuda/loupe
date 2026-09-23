<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\AuthorizeGitHubAppInstallCommand;
use App\Module\GitHub\Command\AuthorizeGitHubAppInstallHandler;
use App\Module\GitHub\Service\GitHubInstallSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The setup URL registered on the App, where GitHub returns a person after an
 * install. It cannot carry the project, so the session names it. The voter
 * runs in the callback, where the project is loaded.
 */
#[Route(
    '/github/app/setup',
    name: 'app_github_app_setup',
    methods: ['GET'],
)]
class SetupGitHubAppController extends AppController
{
    public function __construct(
        private readonly GitHubInstallSession $installSession,
        private readonly AuthorizeGitHubAppInstallHandler $authorizeInstall,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $this->installSession->pending();
        if (null === $pending) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.expired'));

            return $this->redirectToRoute('app_projects');
        }

        try {
            return $this->redirect(($this->authorizeInstall)(new AuthorizeGitHubAppInstallCommand(
                $pending,
                $request->query->has('state') ? $request->query->getString('state') : null,
                $request->query->getInt('installation_id'),
                $this->generateUrl('app_github_app_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            )));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_connect', ['id' => $pending->projectId, '_fragment' => 'repositories']);
        }
    }
}
