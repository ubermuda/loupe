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
use Symfony\Component\Form\FormError;
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
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        // Re-render the edit page with the bound delete form injected — 422 so
        // Turbo renders it (form validation errors and the mismatch error both
        // land as field errors on confirmName, never as a lossy flash).
        //
        // 'id' (the raw route param string) is forwarded alongside the resolved
        // entity because base.html.twig's `current_project()` nav helper reads
        // it from the request attributes — CurrentProjectProvider only accepts a
        // string there, so passing the object alone under 'project' would make
        // it silently resolve to null and shadow the template's own `project`
        // variable (Twig blocks share the parent template's local scope).
        return $this->forward(EditProjectController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'deleteForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
