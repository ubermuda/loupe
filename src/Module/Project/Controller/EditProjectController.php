<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\DeleteProjectFormType;
use App\Module\Project\Form\UpdateProjectRequest;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/edit',
    name: 'app_project_edit',
    methods: ['GET', 'POST'],
)]
class EditProjectController extends AppController
{
    public function __construct(
        private readonly UpdateProjectHandler $updateProjectHandler,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        // The edit form reuses the create form (identical fields); UpdateProjectRequest
        // only adds the factory that pre-fills it from the project.
        $data = UpdateProjectRequest::fromProject($project);
        $form = $this->createForm(CreateProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // NotBlank guarantees a value, but "0" is a legal name that `?:` would wrongly
            // reject — narrow with an explicit empty check instead.
            $name = trim($data->name ?? '');
            if ('' === $name) {
                throw new \LogicException('name required after validation');
            }

            try {
                ($this->updateProjectHandler)(new UpdateProjectCommand(
                    project: $project,
                    name: $name,
                    domain: trim($data->domain ?? '') ?: null,
                    searchLanguage: $data->searchLanguage ?? throw new \LogicException('search language required after validation'),
                ));

                return $this->redirectToRoute('app_projects');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Project/edit_project.html.twig', $form, [
            'project' => $project,
            'deleteForm' => $this->getInjectedFormView($request, 'deleteForm')
                ?? $this->createForm(DeleteProjectFormType::class)->createView(),
        ]);
    }
}
