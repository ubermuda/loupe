<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\StartGitHubAppInstallCommand;
use App\Module\GitHub\Command\StartGitHubAppInstallHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/install',
    name: 'app_github_app_install',
    methods: ['GET'],
)]
class InstallGitHubAppController extends AppController
{
    public function __construct(
        private readonly StartGitHubAppInstallHandler $startInstall,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            return $this->redirect(($this->startInstall)(new StartGitHubAppInstallCommand($project)));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'repositories']);
        }
    }
}
