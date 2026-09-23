<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\CreateGitHubHookCommand;
use App\Module\GitHub\Command\CreateGitHubHookHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\OneTimeHookSecret;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('github-hook-create')]
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/hook',
    name: 'app_github_hook_create',
    methods: ['POST'],
)]
class CreateGitHubHookController extends AppController
{
    public function __construct(
        private readonly CreateGitHubHookHandler $createGitHubHook,
        private readonly OneTimeHookSecret $oneTimeHookSecret,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $hook = ($this->createGitHubHook)(new CreateGitHubHookCommand($project));
            $this->oneTimeHookSecret->put($project, $hook->secret);
            $this->addFlash('success', $this->translator->trans('github.repositories.flash.hook_created'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'repositories']);
    }
}
