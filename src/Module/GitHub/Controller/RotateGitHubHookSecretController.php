<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\RotateGitHubHookSecretCommand;
use App\Module\GitHub\Command\RotateGitHubHookSecretHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\OneTimeHookSecret;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('github-hook-secret-rotate')]
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/hook/secret',
    name: 'app_github_hook_secret_rotate',
    methods: ['POST'],
)]
class RotateGitHubHookSecretController extends AppController
{
    public function __construct(
        private readonly RotateGitHubHookSecretHandler $rotateGitHubHookSecret,
        private readonly OneTimeHookSecret $oneTimeHookSecret,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $hook = ($this->rotateGitHubHookSecret)(new RotateGitHubHookSecretCommand($project));
            $this->oneTimeHookSecret->put($project, $hook->secret);
            $this->addFlash('success', $this->translator->trans('github.repositories.flash.hook_secret_rotated'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'repositories']);
    }
}
