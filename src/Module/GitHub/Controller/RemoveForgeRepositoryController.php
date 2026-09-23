<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Command\RemoveForgeRepositoryCommand;
use App\Module\GitHub\Command\RemoveForgeRepositoryHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('forge-repository-remove')]
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/repositories/{repositoryId}/remove',
    name: 'app_forge_repository_remove',
    requirements: ['repositoryId' => Requirement::UUID],
    methods: ['POST'],
)]
class RemoveForgeRepositoryController extends AppController
{
    public function __construct(
        private readonly RemoveForgeRepositoryHandler $removeForgeRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        // The alias moves the raw project id to `project`, and scoping to it makes a foreign id a 404.
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(repositoryId, project)')] ForgeRepository $repository,
    ): Response {
        try {
            ($this->removeForgeRepository)(new RemoveForgeRepositoryCommand($project, $repository));
            $this->addFlash('success', $this->translator->trans('github.repositories.flash.repository_removed', ['%path%' => $repository->path]));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_connect', ['id' => (string) $project->id, '_fragment' => 'repositories']);
    }
}
