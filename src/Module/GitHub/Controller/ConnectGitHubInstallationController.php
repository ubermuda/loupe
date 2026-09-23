<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\ConnectGitHubInstallationCommand;
use App\Module\GitHub\Command\ConnectGitHubInstallationHandler;
use App\Module\GitHub\Service\GitHubInstallSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The OAuth callback of the App install. The project comes from the session,
 * not the route, so the handler runs the voter.
 */
#[Route(
    '/github/app/callback',
    name: 'app_github_app_callback',
    methods: ['GET'],
)]
class ConnectGitHubInstallationController extends AppController
{
    public function __construct(
        private readonly GitHubInstallSession $installSession,
        private readonly ConnectGitHubInstallationHandler $connectInstallation,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $this->installSession->take();
        if (null === $pending || null === $pending->installationId || null === $pending->authorizeState || null === $pending->codeVerifier) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.expired'));

            return $this->redirectToRoute('app_projects');
        }

        $connectPage = $this->redirectToRoute('app_project_connect', ['id' => $pending->projectId, '_fragment' => 'repositories']);
        $state = $request->query->get('state');
        if (!\is_string($state) || !hash_equals($pending->authorizeState, $state)) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.state_mismatch'));

            return $connectPage;
        }

        $code = $request->query->get('code');
        if (!\is_string($code) || '' === $code) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.not_authorized'));

            return $connectPage;
        }

        try {
            $connected = ($this->connectInstallation)(new ConnectGitHubInstallationCommand(
                $pending->projectId,
                $pending->installationId,
                $code,
                $pending->codeVerifier,
                $this->generateUrl('app_github_app_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return \array_key_exists('project', $e->errors) ? $this->redirectToRoute('app_projects') : $connectPage;
        }

        $this->addFlash('success', $this->translator->trans('github.app.flash.connected', ['%account%' => $connected->installation->accountLogin]));
        if ($connected->refusedRepositories > 0) {
            $this->addFlash('warning', $this->translator->trans('github.app.flash.repositories_elsewhere', ['%count%' => $connected->refusedRepositories]));
        }

        if (!$connected->repositoryListComplete) {
            $this->addFlash('warning', $this->translator->trans('github.app.flash.repositories_incomplete'));
        }

        return $connectPage;
    }
}
