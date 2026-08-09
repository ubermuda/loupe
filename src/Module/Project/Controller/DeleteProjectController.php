<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\DeleteProjectCommand;
use App\Module\Project\Command\DeleteProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\DeleteProjectFormType;
use App\Module\Project\Form\DeleteProjectRequest;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/delete',
    name: 'app_project_delete',
    methods: ['POST'],
)]
final class DeleteProjectController extends AppController
{
    public function __construct(
        private readonly DeleteProjectHandler $deleteProjectHandler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = new DeleteProjectRequest();
        $form = $this->createForm(DeleteProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->deleteProjectHandler)(new DeleteProjectCommand(
                    project: $project,
                    // Byte-for-byte: "typed exactly" tolerates no trimming.
                    confirmedName: $data->confirmName ?? '',
                ));

                $this->addFlash('success', $this->translator->trans('project.delete.flash.success', ['%name%' => $project->name]));

                return $this->redirectToRoute('app_projects');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        // 422 so Turbo renders the re-bound form; errors land on confirmName
        // rather than a lossy flash. 'id' is forwarded alongside the resolved
        // entity because CurrentProjectProvider only accepts a string there —
        // the object alone resolves to null and shadows the template's own
        // `project`.
        return $this->forward(EditProjectController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'deleteForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
